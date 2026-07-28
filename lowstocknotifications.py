"""
StockPilot Demand Forecasting + Auto PO Draft Pipeline
Uses Facebook Prophet to forecast 2-week demand per product per store.

Actions taken:
  - predicted demand > current stock  → low-stock notification + draft PO created
  - predicted demand < current_stock/2 → overstock notification
  - otherwise                          → no action

Run via cron (e.g. daily at 5 AM):
  python lowstocknotifications.py

Requirements:
  pip install prophet pandas mysql-connector-python
"""

import json
import logging
from datetime import datetime, date, timedelta

import mysql.connector
import pandas as pd
from prophet import Prophet

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger(__name__)

DB_CONFIG = dict(host="localhost", user="root", password="", database="inventory_management")

# ---------------------------------------------------------------------------
# Database helpers
# ---------------------------------------------------------------------------

def get_conn():
    return mysql.connector.connect(**DB_CONFIG)


def insert_notification(cursor, store_id, title, message, notif_type, data: dict):
    cursor.execute(
        """
        INSERT INTO notifications (store_id, user_id, title, message, type, status, data, created_at)
        VALUES (%s, NULL, %s, %s, %s, 'unread', %s, %s)
        """,
        (store_id, title, message, notif_type, json.dumps(data), datetime.now().strftime("%Y-%m-%d %H:%M:%S")),
    )


def notification_sent_recently(cursor, store_id, title, hours=20):
    """Return True if the same title was inserted in the last `hours` hours."""
    cursor.execute(
        """
        SELECT id FROM notifications
        WHERE store_id = %s AND title = %s
          AND created_at >= DATE_SUB(NOW(), INTERVAL %s HOUR)
        LIMIT 1
        """,
        (store_id, title, hours),
    )
    return cursor.fetchone() is not None


def get_preferred_supplier(cursor, product_id, store_id):
    """Return (supplier_id, unit_cost, payment_days) or None."""
    cursor.execute(
        """
        SELECT ps.supplier_id, ps.unit_cost, COALESCE(s.payment_days, 30) AS payment_days
        FROM product_suppliers ps
        JOIN suppliers s ON ps.supplier_id = s.supplier_id
        WHERE ps.product_id = %s AND ps.store_id = %s AND ps.is_primary = 1
          AND s.status = 'active'
        LIMIT 1
        """,
        (product_id, store_id),
    )
    return cursor.fetchone()


def get_reorder_config(cursor, product_id, store_id):
    """Return (min_stock_level, max_stock_level, reorder_quantity) with defaults."""
    cursor.execute(
        """
        SELECT COALESCE(min_stock_level, 10)  AS min_stock,
               COALESCE(max_stock_level, 100) AS max_stock,
               COALESCE(reorder_quantity, 50) AS reorder_qty
        FROM inventory_alerts
        WHERE product_id = %s AND store_id = %s AND alert_enabled = 1
        LIMIT 1
        """,
        (product_id, store_id),
    )
    row = cursor.fetchone()
    if row:
        return row["min_stock"], row["max_stock"], row["reorder_qty"]
    return 10, 100, 50


def draft_po_exists_today(cursor, store_id, supplier_id):
    cursor.execute(
        """
        SELECT po_id FROM purchase_orders
        WHERE store_id = %s AND supplier_id = %s AND status = 'draft'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        LIMIT 1
        """,
        (store_id, supplier_id),
    )
    return cursor.fetchone()


def create_or_append_draft_po(cursor, store_id, supplier_id, product_id,
                               product_name, suggested_qty, unit_cost, payment_days):
    """
    If a draft PO already exists today for this store+supplier, append items to it.
    Otherwise create a new draft PO. Returns po_id.
    """
    existing = draft_po_exists_today(cursor, store_id, supplier_id)

    if existing:
        po_id = existing["po_id"]
        # Check if this product is already on the PO
        cursor.execute(
            "SELECT poi_id FROM purchase_order_items WHERE po_id = %s AND product_id = %s LIMIT 1",
            (po_id, product_id),
        )
        if cursor.fetchone():
            return po_id  # already on this PO, skip duplicate

        total_price = suggested_qty * unit_cost
        cursor.execute(
            """
            INSERT INTO purchase_order_items
                (store_id, po_id, product_id, quantity_ordered, quantity_received, unit_price, total_price)
            VALUES (%s, %s, %s, %s, 0, %s, %s)
            """,
            (store_id, po_id, product_id, suggested_qty, unit_cost, total_price),
        )
        # Update PO total
        cursor.execute(
            "UPDATE purchase_orders SET total_amount = total_amount + %s, updated_at = NOW() WHERE po_id = %s",
            (total_price, po_id),
        )
        return po_id

    # Create new draft PO
    po_number      = f"PO-FCST-{datetime.now().strftime('%Y%m%d-%H%M%S')}-{store_id}-{supplier_id}"
    expected_del   = (date.today() + timedelta(days=max(7, payment_days))).strftime("%Y-%m-%d")
    total_price    = suggested_qty * unit_cost

    cursor.execute(
        """
        INSERT INTO purchase_orders
            (po_number, supplier_id, store_id, order_date, expected_delivery_date,
             total_amount, status, created_by, notes)
        VALUES (%s, %s, %s, CURDATE(), %s, %s, 'draft', 1,
                'Auto-generated by demand forecast')
        """,
        (po_number, supplier_id, store_id, expected_del, total_price),
    )
    po_id = cursor.lastrowid

    cursor.execute(
        """
        INSERT INTO purchase_order_items
            (store_id, po_id, product_id, quantity_ordered, quantity_received, unit_price, total_price)
        VALUES (%s, %s, %s, %s, 0, %s, %s)
        """,
        (store_id, po_id, product_id, suggested_qty, unit_cost, total_price),
    )
    return po_id


# ---------------------------------------------------------------------------
# Forecasting
# ---------------------------------------------------------------------------

def forecast_demand(grouped_df):
    """Return predicted weekly demand for the next 2 weeks (summed)."""
    if len(grouped_df) < 2:
        return None
    try:
        m = Prophet(weekly_seasonality=True, daily_seasonality=False, yearly_seasonality=False)
        m.fit(grouped_df)
        future   = m.make_future_dataframe(periods=2, freq="W")
        forecast = m.predict(future)
        # Sum the last 2 predictions (next 2 weeks)
        predicted = int(max(0, forecast["yhat"].iloc[-2:].sum()))
        return predicted
    except Exception as exc:
        log.warning("Prophet failed: %s", exc)
        return None


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main():
    conn   = get_conn()
    cursor = conn.cursor(dictionary=True)

    cursor.execute("SELECT DISTINCT store_id FROM reports WHERE type = 'sold'")
    store_ids = [r["store_id"] for r in cursor.fetchall()]
    log.info("Processing %d store(s)", len(store_ids))

    for store_id in store_ids:
        cursor.execute(
            """
            SELECT r.timestamp, r.quantity, r.product_name,
                   p.id AS product_id, p.quantity AS current_stock,
                   p.cost_price
            FROM reports r
            JOIN products p ON p.product_name = r.product_name AND p.store_id = r.store_id
            WHERE r.store_id = %s AND r.type = 'sold'
            """,
            (store_id,),
        )
        rows = cursor.fetchall()
        if not rows:
            continue

        data = pd.DataFrame(rows)
        data["timestamp"] = pd.to_datetime(data["timestamp"])

        for product_name in data["product_name"].unique():
            prod_data    = data[data["product_name"] == product_name].copy()
            product_id   = int(prod_data["product_id"].iloc[0])
            current_stock = int(prod_data["current_stock"].iloc[0])
            cost_price    = float(prod_data["cost_price"].iloc[0])

            grouped = (
                prod_data.groupby(pd.Grouper(key="timestamp", freq="W"))["quantity"]
                .sum()
                .reset_index()
                .rename(columns={"timestamp": "ds", "quantity": "y"})
            )

            predicted_qty = forecast_demand(grouped)
            if predicted_qty is None:
                continue

            log.info("Store %d | %s | stock=%d | predicted=%d",
                     store_id, product_name, current_stock, predicted_qty)

            # ---- Low stock: forecast demand exceeds current stock ----
            if predicted_qty > current_stock:
                title = f"Forecast Low Stock: {product_name}"
                msg   = (f"Demand forecast predicts {predicted_qty} units needed "
                         f"but only {current_stock} in stock.")

                if not notification_sent_recently(cursor, store_id, title):
                    insert_notification(cursor, store_id, title, msg, "low_stock", {
                        "product_id": product_id, "product_name": product_name,
                        "current_stock": current_stock, "predicted_qty": predicted_qty,
                    })

                # Create / append draft PO if a preferred supplier exists
                supplier = get_preferred_supplier(cursor, product_id, store_id)
                if supplier:
                    min_s, max_s, reorder_q = get_reorder_config(cursor, product_id, store_id)
                    deficit       = max(0, max_s - current_stock)
                    suggested_qty = max(reorder_q, deficit, predicted_qty - current_stock)
                    unit_cost     = float(supplier["unit_cost"] or cost_price)
                    payment_days  = int(supplier["payment_days"])

                    po_id = create_or_append_draft_po(
                        cursor, store_id, supplier["supplier_id"],
                        product_id, product_name, suggested_qty,
                        unit_cost, payment_days,
                    )
                    log.info("  → Draft PO #%d updated/created (qty %d)", po_id, suggested_qty)

                    # Notify about the PO
                    po_title = f"Forecast PO Created for {product_name}"
                    po_msg   = (f"Draft PO #{po_id} created — {suggested_qty} units "
                                f"@ ₹{unit_cost:.2f}. Forecast demand: {predicted_qty}.")
                    if not notification_sent_recently(cursor, store_id, po_title):
                        insert_notification(cursor, store_id, po_title, po_msg, "purchase_order", {
                            "po_id": po_id, "product_id": product_id,
                            "suggested_qty": suggested_qty, "predicted_qty": predicted_qty,
                        })

            # ---- Overstock: forecast well below half current stock ----
            elif predicted_qty < current_stock / 2:
                title = f"Overstock Alert: {product_name}"
                msg   = (f"'{product_name}' may be overstocked — "
                         f"{current_stock} units on hand, forecast only {predicted_qty}.")
                if not notification_sent_recently(cursor, store_id, title):
                    insert_notification(cursor, store_id, title, msg, "low_stock", {
                        "product_id": product_id, "product_name": product_name,
                        "current_stock": current_stock, "predicted_qty": predicted_qty,
                    })

        conn.commit()

    cursor.close()
    conn.close()
    log.info("Forecasting pipeline complete.")


if __name__ == "__main__":
    main()
