-- Migration: Add P.O. Number field to purchases table
-- Date: 2026-02-04
-- Description: Adds optional po_number field to track purchase order numbers

ALTER TABLE purchases ADD COLUMN po_number VARCHAR(50) NULL AFTER purchase_number;

-- Add index for searching by PO number
ALTER TABLE purchases ADD INDEX idx_purchases_po_number (po_number);
