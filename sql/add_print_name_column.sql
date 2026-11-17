-- Add print_name column to sales table
ALTER TABLE sales ADD COLUMN print_name VARCHAR(250) NULL AFTER customer_id;
