ALTER TABLE customers
  ADD COLUMN google_sub varchar(64) NULL AFTER userpassword,
  ADD COLUMN email varchar(255) NULL AFTER google_sub,
  ADD COLUMN display_name varchar(255) NULL AFTER email,
  ADD UNIQUE KEY customers_google_sub_unique (google_sub),
  ADD UNIQUE KEY customers_email_unique (email);
