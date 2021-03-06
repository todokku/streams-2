SELECT pa.`name` as `persona_name`, pa.`display_name`, pa.`guid` as `persona_guid`,
       CONCAT(CASE WHEN si.`https` = 'Y' THEN 'https' ELSE 'http' END, '://', su.`url`, '/avatars/', pa.`avatar_img`) as `avatar_url`,
       CONCAT(CASE WHEN si.`https` = 'Y' THEN 'https' ELSE 'http' END, '://', su.`url`, '/', pa.`guid`, '/profile') as `profile_url`,
       po.`id` as `post_id`, po.`thread_id`, po.`parent_id`, po.`title`, po.`value`,
       (SELECT CASE WHEN COUNT(z.`key`) > 0 THEN 'Y' ELSE 'N' END FROM `PostMeta` z WHERE z.`is_deleted` = 'N' and z.`post_id` = po.`id` LIMIT 1) as `has_meta`,
       (SELECT GROUP_CONCAT(z.`value`) as `value` FROM `PostTags` z WHERE z.`is_deleted` = 'N' and z.`post_id` = po.`id`) as `post_tags`,
       (SELECT CASE WHEN COUNT(z.`persona_id`) > 0 THEN 'Y' ELSE 'N' END FROM `PostMention` z WHERE z.`is_deleted` = 'N' and z.`post_id` = po.`id`) as `has_mentions`,
       CONCAT(CASE WHEN si.`https` = 'Y' THEN 'https' ELSE 'http' END, '://', su.`url`, po.`canonical_url`) as `canonical_url`,
       CONCAT(CASE WHEN si.`https` = 'Y' THEN 'https' ELSE 'http' END, '://', su.`url`) as `site_url`,

       (SELECT pp.`pin_type` FROM `PostAction` pp INNER JOIN `Persona` pz ON pp.`persona_id` = pz.`id`
         WHERE pp.`is_deleted` = 'N' and pz.`is_deleted` = 'N' and pp.`post_id` = po.`id` and pz.`account_id` = [ACCOUNT_ID]) as `pin_type`,
       (SELECT pp.`is_starred` FROM `PostAction` pp INNER JOIN `Persona` pz ON pp.`persona_id` = pz.`id`
         WHERE pp.`is_deleted` = 'N' and pz.`is_deleted` = 'N' and pp.`post_id` = po.`id` and pz.`account_id` = [ACCOUNT_ID]) as `is_starred`,
       (SELECT pp.`is_muted` FROM `PostAction` pp INNER JOIN `Persona` pz ON pp.`persona_id` = pz.`id`
         WHERE pp.`is_deleted` = 'N' and pz.`is_deleted` = 'N' and pp.`post_id` = po.`id` and pz.`account_id` = [ACCOUNT_ID]) as `is_muted`,
       (SELECT pp.`points` FROM `PostAction` pp INNER JOIN `Persona` pz ON pp.`persona_id` = pz.`id`
         WHERE pp.`is_deleted` = 'N' and pz.`is_deleted` = 'N' and pp.`post_id` = po.`id` and pz.`account_id` = [ACCOUNT_ID]) as `points`,

       po.`reply_to`, po.`type`,
       po.`guid` as `post_guid`, po.`privacy_type`,
       po.`publish_at`, po.`expires_at`, po.`updated_at`,
       CASE WHEN pa.`account_id` = [ACCOUNT_ID] THEN 'Y' ELSE 'N' END as `is_you`,
       CASE WHEN po.`expires_at` IS NULL THEN 'Y'
            WHEN po.`expires_at` < Now() THEN 'N'
            ELSE 'Y' END as `is_visible`
  FROM `SiteUrl` su INNER JOIN `Site` si ON su.`site_id` = si.`id`
                    INNER JOIN `Channel` ch ON si.`id` = ch.`site_id`
                    INNER JOIN `Post` po ON ch.`id` = po.`channel_id`
                    INNER JOIN `Persona` pa ON po.`persona_id` = pa.`id`
 WHERE su.`is_deleted` = 'N' and si.`is_deleted` = 'N' and ch.`is_deleted` = 'N' and po.`is_deleted` = 'N' and pa.`is_deleted` = 'N'
   and ch.`type` = 'channel.site' and ch.`privacy_type` = 'visibility.public' and su.`is_active` = 'Y'
   and po.`privacy_type` IN ('visibility.public', 'visibility.private', 'visibility.none') and po.`type` IN ([POST_TYPES])
   and 'Y' = CASE WHEN po.`privacy_type` = 'visibility.public' THEN 'Y'
                  WHEN pa.`account_id` = [ACCOUNT_ID] THEN 'Y'
                  ELSE 'N' END
   and po.`publish_at` BETWEEN CASE WHEN [SINCE_UNIX] = 0 THEN DATE_SUB(Now(), INTERVAL 2 WEEK) ELSE FROM_UNIXTIME([SINCE_UNIX]) END AND
                               CASE WHEN [UNTIL_UNIX] = 0 THEN Now() ELSE FROM_UNIXTIME([UNTIL_UNIX]) END
 ORDER BY CASE WHEN [SINCE_UNIX] = 0 THEN 1 ELSE po.`publish_at` END, po.`publish_at` DESC
 LIMIT 0, [COUNT];