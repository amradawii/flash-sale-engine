-- The test suite runs against real MySQL, isolated from the demo data.
CREATE DATABASE IF NOT EXISTS flash_sale_testing;
GRANT ALL PRIVILEGES ON flash_sale_testing.* TO 'flash_sale'@'%';
