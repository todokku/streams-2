INSERT INTO `UsageStats` (`site_id`, `token_id`, `http_code`, `request_type`, `request_uri`, `referrer`,
                          `event_at`, `event_on`, `from_ip`, `device_id`,
                          `agent`, `platform`, `browser`, `version`,
                          `seconds`, `sqlops`, `message`)
SELECT CASE WHEN [SITE_ID] <= 0 THEN NULL ELSE [SITE_ID] END,
       CASE WHEN [TOKEN_ID] <= 0 THEN NULL ELSE [TOKEN_ID] END,
       CASE WHEN [HTTP_CODE] <= 0 THEN 200 ELSE [HTTP_CODE] END,
       CASE WHEN '[REQ_TYPE]' = '' THEN 'GET' ELSE LEFT('[REQ_TYPE]', 8) END,
       CASE WHEN '[REQ_URI]' = '' THEN '/' ELSE LEFT('[REQ_URI]', 512) END,
       CASE WHEN '[REFERER]' = '' THEN NULL ELSE LEFT('[REFERER]', 1024) END,
       Now(), DATE_FORMAT(Now(), '%Y-%m-%d'), LEFT('[IP_ADDR]', 64),
       CASE WHEN '[DEVICE_ID]' = '' THEN NULL ELSE LEFT('[DEVICE_ID]', 64) END,
       CASE WHEN '[AGENT]' = '' THEN NULL ELSE LEFT('[AGENT]', 2048) END,
       CASE WHEN '[UAPLATFORM]' = '' THEN NULL ELSE LEFT('[UAPLATFORM]', 64) END,
       CASE WHEN '[UABROWSER]' = '' THEN 'Unknown' ELSE LEFT('[UABROWSER]', 64) END,
       CASE WHEN '[UAVERSION]' = '' THEN NULL ELSE LEFT('[UAVERSION]', 64) END,
       CASE WHEN [RUNTIME] < 0 THEN ([RUNTIME] * -1) ELSE [RUNTIME] END,
       [SQL_OPS],
       CASE WHEN '[MESSAGE]' = '' THEN NULL ELSE LEFT('[MESSAGE]', 512) END;