CREATE TABLE IF NOT EXISTS team_bot (
  tb_id varchar(30) primary key,
  tb_team_id varchar(15),
  tb_bot_id varchar(15),
  tb_bot_token varchar(100),
  tb_batch_id int,
  tb_last_activity int,
  tb_users_token text,
  tb_rooms text
);
