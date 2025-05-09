SET @rownum := 0;

INSERT INTO requests (
  group_id, 
  date_submitted, 
  shift, 
  cdng, 
  ceh_krs, 
  brigade, 
  master, 
  kust, 
  skvazhina, 
  type, 
  description, 
  required_date, 
  required_time, 
  status
)
SELECT 
  id,
  CURDATE(),
  '1',
  '1',
  'Цех КРС-1',
  CONCAT('Бригада-', FLOOR(RAND()*100)),
  CONCAT('Мастер-', FLOOR(RAND()*100)),
  CONCAT('Куст-', FLOOR(RAND()*100)),
  CONCAT('Скважина-', FLOOR(RAND()*100)),
  CASE WHEN RAND() < 0.5 THEN 'вывоз' ELSE 'завоз' END,
  CONCAT('Описание запроса № ', id),
  DATE_ADD(CURDATE(), INTERVAL FLOOR(RAND()*10)+1 DAY),
  CONCAT(FLOOR(RAND()*12), ':', LPAD(FLOOR(RAND()*60), 2, '0')),
  'new'
FROM (
  SELECT @rownum:=@rownum+1 AS id 
  FROM (
    SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 
    UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10
  ) a,
  (
    SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 
    UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10
  ) b,
  (
    SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 
    UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10
  ) c,
  (
    SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 
    UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10
  ) d
  LIMIT 10000
) t;
