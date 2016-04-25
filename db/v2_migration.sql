-- sqlite3 bot2hook.db < /http/db/v2_migration.sql

ALTER TABLE team_bot ADD COLUMN tb_batch_id int;
ALTER TABLE team_bot ADD COLUMN tb_last_activity int;
