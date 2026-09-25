-- Marine Team — the whole schema, ported model for model from the original
-- Prisma schema (PORT_PROMPT.md Appendix B), plus the tables the port itself
-- needs (sessions, services, jobs, email log, throttles, tokens).
--
-- Conventions (see the brief's "Database" section):
--   * {{name}} is the prefixed table; the migrator expands it.
--   * ids are VARCHAR(32) ascii_bin: imported cuids stay as they are.
--   * DATETIME(3) is always UTC.
--   * enums are VARCHAR(32), their values enforced in PHP.
--   * String[] columns are JSON; where one is queried, a join table is kept
--     in step by the module that writes it (series_tags, video_scripture_books).
--   * Every statement is idempotent (IF NOT EXISTS), because MySQL DDL is not
--     transactional and a slow host can time out half way through this file.
--   * Tables are in dependency order, so every foreign key is declared inline.

CREATE TABLE IF NOT EXISTS {{users}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  auth0_id VARCHAR(255) NULL,
  email VARCHAR(255) NOT NULL,
  name VARCHAR(255) NULL,
  display_name VARCHAR(255) NULL,
  picture VARCHAR(2000) NULL,
  role VARCHAR(32) NOT NULL DEFAULT 'MEMBER',
  authorized TINYINT(1) NOT NULL DEFAULT 0,
  notification_frequency VARCHAR(32) NOT NULL DEFAULT 'INSTANT',
  email_notifications TINYINT(1) NOT NULL DEFAULT 0,
  phone VARCHAR(64) NULL,
  sms_opt_in TINYINT(1) NOT NULL DEFAULT 0,
  broadcast_emails TINYINT(1) NOT NULL DEFAULT 1,
  directory_listed TINYINT(1) NOT NULL DEFAULT 0,
  directory_show_email TINYINT(1) NOT NULL DEFAULT 0,
  directory_show_phone TINYINT(1) NOT NULL DEFAULT 0,
  directory_note VARCHAR(500) NULL,
  calendar_token VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
  -- Local sign-in (the port's): null for members who sign in elsewhere.
  password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
  email_verified_at DATETIME(3) NULL,
  -- An email change waiting for the new address to be verified.
  pending_email VARCHAR(255) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY users_auth0_id_key (auth0_id),
  UNIQUE KEY users_email_key (email),
  UNIQUE KEY users_calendar_token_key (calendar_token),
  KEY users_directory_idx (directory_listed, authorized)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{user_identities}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  sub VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  provider VARCHAR(64) NOT NULL,
  email VARCHAR(255) NOT NULL,
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY user_identities_sub_key (sub),
  KEY user_identities_user_idx (user_id),
  KEY user_identities_email_idx (email),
  CONSTRAINT {prefix}user_identities_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{categories}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(191) NOT NULL,
  description TEXT NULL,
  cover_image_url VARCHAR(2000) NULL,
  tags JSON NOT NULL,
  member_only TINYINT(1) NOT NULL DEFAULT 0,
  download_enabled TINYINT(1) NULL,
  hidden TINYINT(1) NOT NULL DEFAULT 0,
  published TINYINT(1) NOT NULL DEFAULT 1,
  publish_at DATETIME(3) NULL,
  unpublish_at DATETIME(3) NULL,
  deleted_at DATETIME(3) NULL,
  featured TINYINT(1) NOT NULL DEFAULT 0,
  require_sequential TINYINT(1) NOT NULL DEFAULT 0,
  hymnal_style TINYINT(1) NOT NULL DEFAULT 0,
  position INT NOT NULL DEFAULT 0,
  pinned TINYINT(1) NOT NULL DEFAULT 0,
  parent_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY categories_slug_key (slug),
  KEY categories_parent_idx (parent_id),
  FULLTEXT KEY categories_name_ft (name),
  CONSTRAINT {prefix}categories_parent_fk FOREIGN KEY (parent_id) REFERENCES {{categories}} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{series}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(191) NOT NULL,
  description TEXT NULL,
  language VARCHAR(35) NULL,
  cover_image_url VARCHAR(2000) NULL,
  abbreviation VARCHAR(32) NULL,
  hymn_per_file TINYINT(1) NOT NULL DEFAULT 0,
  member_only TINYINT(1) NOT NULL DEFAULT 0,
  download_enabled TINYINT(1) NULL,
  hidden TINYINT(1) NOT NULL DEFAULT 0,
  published TINYINT(1) NOT NULL DEFAULT 1,
  publish_at DATETIME(3) NULL,
  unpublish_at DATETIME(3) NULL,
  deleted_at DATETIME(3) NULL,
  featured TINYINT(1) NOT NULL DEFAULT 0,
  pinned TINYINT(1) NOT NULL DEFAULT 0,
  tags JSON NOT NULL,
  position INT NOT NULL DEFAULT 0,
  category_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  view_count INT NOT NULL DEFAULT 0,
  require_sequential TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY series_slug_key (slug),
  KEY series_category_idx (category_id),
  KEY series_language_idx (language),
  FULLTEXT KEY series_text_ft (title, description),
  CONSTRAINT {prefix}series_category_fk FOREIGN KEY (category_id) REFERENCES {{categories}} (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Series.tags, queryable: /tags/[tag] and search read this, not the JSON.
CREATE TABLE IF NOT EXISTS {{series_tags}} (
  series_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  tag VARCHAR(191) NOT NULL,
  PRIMARY KEY (series_id, tag),
  KEY series_tags_tag_idx (tag),
  CONSTRAINT {prefix}series_tags_series_fk FOREIGN KEY (series_id) REFERENCES {{series}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{category_editors}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  category_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY category_editors_user_category_key (user_id, category_id),
  CONSTRAINT {prefix}category_editors_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}category_editors_category_fk FOREIGN KEY (category_id) REFERENCES {{categories}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{series_editors}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  series_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY series_editors_user_series_key (user_id, series_id),
  CONSTRAINT {prefix}series_editors_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}series_editors_series_fk FOREIGN KEY (series_id) REFERENCES {{series}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{speakers}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(191) NOT NULL,
  bio TEXT NULL,
  photo_url VARCHAR(2000) NULL,
  position INT NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY speakers_slug_key (slug),
  FULLTEXT KEY speakers_name_ft (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{video_feeds}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  kind VARCHAR(32) NOT NULL,
  external_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  name VARCHAR(255) NOT NULL,
  series_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  category_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  auto_publish TINYINT(1) NOT NULL DEFAULT 0,
  look_back INT NOT NULL DEFAULT 25,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  last_synced_at DATETIME(3) NULL,
  last_sync_status VARCHAR(32) NULL,
  last_error TEXT NULL,
  fingerprint VARCHAR(128) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY video_feeds_kind_external_key (kind, external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Video.source became `provider` (bunny, youtube, vimeo, dropbox, gdrive,
-- onedrive, archive, s3, direct, host, or a plugin's id); `external_id` is
-- whatever identifies the video at that provider (the Bunny guid included),
-- and `provider_data` holds the rest.
CREATE TABLE IF NOT EXISTS {{videos}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(191) NOT NULL,
  description TEXT NULL,
  provider VARCHAR(32) NOT NULL DEFAULT 'bunny',
  external_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  provider_data JSON NULL,
  bunny_library_id VARCHAR(64) NULL,
  external_url VARCHAR(2000) NULL,
  external_thumbnail_url VARCHAR(2000) NULL,
  imported_title VARCHAR(255) NULL,
  imported_description TEXT NULL,
  feed_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  thumbnail_file_name VARCHAR(255) NULL,
  has_mp4_fallback TINYINT(1) NULL,
  mp4_resolutions VARCHAR(255) NULL,
  language VARCHAR(35) NULL,
  transcript MEDIUMTEXT NULL,
  transcript_status VARCHAR(32) NULL,
  transcript_error TEXT NULL,
  transcript_started_at DATETIME(3) NULL,
  note_outline MEDIUMTEXT NULL,
  scripture_refs JSON NOT NULL,
  duration_seconds INT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'PROCESSING',
  member_only TINYINT(1) NOT NULL DEFAULT 0,
  download_enabled TINYINT(1) NULL,
  hidden TINYINT(1) NOT NULL DEFAULT 0,
  published TINYINT(1) NOT NULL DEFAULT 0,
  publish_at DATETIME(3) NULL,
  unpublish_at DATETIME(3) NULL,
  deleted_at DATETIME(3) NULL,
  is_premiere TINYINT(1) NOT NULL DEFAULT 0,
  position INT NOT NULL DEFAULT 0,
  series_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  category_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  speaker_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  view_count INT NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY videos_slug_key (slug),
  UNIQUE KEY videos_provider_external_key (provider, external_id),
  KEY videos_series_idx (series_id),
  KEY videos_category_idx (category_id),
  KEY videos_speaker_idx (speaker_id),
  KEY videos_language_idx (language),
  KEY videos_feed_idx (feed_id),
  KEY videos_transcript_status_idx (transcript_status),
  FULLTEXT KEY videos_text_ft (title, description),
  FULLTEXT KEY videos_transcript_ft (transcript),
  CONSTRAINT {prefix}videos_feed_fk FOREIGN KEY (feed_id) REFERENCES {{video_feeds}} (id) ON DELETE SET NULL,
  CONSTRAINT {prefix}videos_series_fk FOREIGN KEY (series_id) REFERENCES {{series}} (id) ON DELETE SET NULL,
  CONSTRAINT {prefix}videos_category_fk FOREIGN KEY (category_id) REFERENCES {{categories}} (id) ON DELETE SET NULL,
  CONSTRAINT {prefix}videos_speaker_fk FOREIGN KEY (speaker_id) REFERENCES {{speakers}} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Video.scriptureRefs, queryable by book for /scripture/[book].
CREATE TABLE IF NOT EXISTS {{video_scripture_books}} (
  video_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  book VARCHAR(100) NOT NULL,
  PRIMARY KEY (video_id, book),
  KEY video_scripture_books_book_idx (book),
  CONSTRAINT {prefix}video_scripture_books_video_fk FOREIGN KEY (video_id) REFERENCES {{videos}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{chapters}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  video_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title VARCHAR(255) NOT NULL,
  timestamp_seconds INT NOT NULL,
  position INT NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY chapters_video_idx (video_id),
  CONSTRAINT {prefix}chapters_video_fk FOREIGN KEY (video_id) REFERENCES {{videos}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{series_favorites}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  series_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY series_favorites_user_series_key (user_id, series_id),
  KEY series_favorites_series_idx (series_id),
  CONSTRAINT {prefix}series_favorites_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}series_favorites_series_fk FOREIGN KEY (series_id) REFERENCES {{series}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{video_favorites}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  video_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY video_favorites_user_video_key (user_id, video_id),
  KEY video_favorites_video_idx (video_id),
  CONSTRAINT {prefix}video_favorites_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}video_favorites_video_fk FOREIGN KEY (video_id) REFERENCES {{videos}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FileAsset. `backend` says which Files provider holds the bytes (local,
-- bunny, or a plugin's); `storage_path` is where (the original bunnyPath).
CREATE TABLE IF NOT EXISTS {{file_assets}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title VARCHAR(255) NOT NULL,
  backend VARCHAR(32) NOT NULL DEFAULT 'local',
  storage_path VARCHAR(1000) NOT NULL,
  url VARCHAR(2000) NOT NULL DEFAULT '',
  size_bytes BIGINT NULL,
  mime_type VARCHAR(191) NULL,
  member_only TINYINT(1) NOT NULL DEFAULT 0,
  hidden TINYINT(1) NOT NULL DEFAULT 0,
  published TINYINT(1) NOT NULL DEFAULT 1,
  publish_at DATETIME(3) NULL,
  unpublish_at DATETIME(3) NULL,
  deleted_at DATETIME(3) NULL,
  position INT NOT NULL DEFAULT 0,
  page_number INT NULL,
  group_label VARCHAR(255) NULL,
  lyrics_text MEDIUMTEXT NULL,
  ccli_number VARCHAR(64) NULL,
  song_author VARCHAR(500) NULL,
  song_copyright VARCHAR(500) NULL,
  musical_key VARCHAR(16) NULL,
  tempo_bpm INT NULL,
  page_offset INT NOT NULL DEFAULT 0,
  cover_data_url MEDIUMTEXT NULL,
  hymn_count INT NULL,
  contents_indexed_at DATETIME(3) NULL,
  text_indexed_at DATETIME(3) NULL,
  podcast_published TINYINT(1) NOT NULL DEFAULT 0,
  public_path VARCHAR(1000) NULL,
  -- Set while a finalised upload is still being pushed to remote storage.
  upload_pending TINYINT(1) NOT NULL DEFAULT 0,
  series_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  category_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY file_assets_series_idx (series_id),
  KEY file_assets_category_idx (category_id),
  FULLTEXT KEY file_assets_title_ft (title),
  CONSTRAINT {prefix}file_assets_series_fk FOREIGN KEY (series_id) REFERENCES {{series}} (id) ON DELETE SET NULL,
  CONSTRAINT {prefix}file_assets_category_fk FOREIGN KEY (category_id) REFERENCES {{categories}} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{book_hymns}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  file_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title VARCHAR(500) NOT NULL,
  number INT NULL,
  page INT NOT NULL,
  depth INT NOT NULL DEFAULT 0,
  position INT NOT NULL,
  PRIMARY KEY (id),
  KEY book_hymns_file_idx (file_id),
  KEY book_hymns_number_idx (number),
  KEY book_hymns_title_idx (title(191)),
  CONSTRAINT {prefix}book_hymns_file_fk FOREIGN KEY (file_id) REFERENCES {{file_assets}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{book_pages}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  file_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  page INT NOT NULL,
  text MEDIUMTEXT NOT NULL,
  source VARCHAR(16) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY book_pages_file_page_key (file_id, page),
  KEY book_pages_file_idx (file_id),
  FULLTEXT KEY book_pages_text_ft (text),
  CONSTRAINT {prefix}book_pages_file_fk FOREIGN KEY (file_id) REFERENCES {{file_assets}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{book_hymn_details}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  file_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  number INT NOT NULL,
  lyrics_text MEDIUMTEXT NULL,
  ccli_number VARCHAR(64) NULL,
  author VARCHAR(500) NULL,
  copyright VARCHAR(500) NULL,
  musical_key VARCHAR(16) NULL,
  tempo_bpm INT NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY book_hymn_details_file_number_key (file_id, number),
  KEY book_hymn_details_file_idx (file_id),
  CONSTRAINT {prefix}book_hymn_details_file_fk FOREIGN KEY (file_id) REFERENCES {{file_assets}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{file_favorites}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  file_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY file_favorites_user_file_key (user_id, file_id),
  KEY file_favorites_file_idx (file_id),
  CONSTRAINT {prefix}file_favorites_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}file_favorites_file_fk FOREIGN KEY (file_id) REFERENCES {{file_assets}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{service_plans}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title VARCHAR(255) NOT NULL,
  service_date DATETIME(3) NULL,
  notes TEXT NULL,
  published TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY service_plans_date_idx (service_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{service_teams}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(255) NOT NULL,
  position INT NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{service_team_members}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  team_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  position VARCHAR(255) NULL,
  joined_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY service_team_members_team_user_key (team_id, user_id),
  KEY service_team_members_user_idx (user_id),
  CONSTRAINT {prefix}service_team_members_team_fk FOREIGN KEY (team_id) REFERENCES {{service_teams}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}service_team_members_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{service_assignments}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  plan_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  team_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  position VARCHAR(191) NOT NULL DEFAULT '',
  status VARCHAR(32) NOT NULL DEFAULT 'INVITED',
  note TEXT NULL,
  responded_at DATETIME(3) NULL,
  cover_wanted TINYINT(1) NOT NULL DEFAULT 0,
  cover_note TEXT NULL,
  cover_asked_at DATETIME(3) NULL,
  covered_for_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  covered_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY service_assignments_plan_user_position_key (plan_id, user_id, position),
  KEY service_assignments_team_cover_idx (team_id, cover_wanted),
  KEY service_assignments_plan_idx (plan_id),
  KEY service_assignments_user_status_idx (user_id, status),
  CONSTRAINT {prefix}service_assignments_plan_fk FOREIGN KEY (plan_id) REFERENCES {{service_plans}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}service_assignments_team_fk FOREIGN KEY (team_id) REFERENCES {{service_teams}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}service_assignments_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}service_assignments_covered_fk FOREIGN KEY (covered_for_id) REFERENCES {{users}} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{service_blockouts}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  start_date DATETIME(3) NOT NULL,
  end_date DATETIME(3) NOT NULL,
  reason VARCHAR(500) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY service_blockouts_user_start_idx (user_id, start_date),
  CONSTRAINT {prefix}service_blockouts_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{service_plan_items}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  plan_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  file_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  hymn_number INT NULL,
  note VARCHAR(500) NULL,
  position INT NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY service_plan_items_plan_idx (plan_id),
  KEY service_plan_items_file_idx (file_id),
  CONSTRAINT {prefix}service_plan_items_plan_fk FOREIGN KEY (plan_id) REFERENCES {{service_plans}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}service_plan_items_file_fk FOREIGN KEY (file_id) REFERENCES {{file_assets}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{comments}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  series_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  video_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  body TEXT NOT NULL,
  parent_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  hidden TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY comments_series_idx (series_id),
  KEY comments_video_idx (video_id),
  KEY comments_parent_idx (parent_id),
  CONSTRAINT {prefix}comments_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}comments_series_fk FOREIGN KEY (series_id) REFERENCES {{series}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}comments_video_fk FOREIGN KEY (video_id) REFERENCES {{videos}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}comments_parent_fk FOREIGN KEY (parent_id) REFERENCES {{comments}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{comment_reports}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  comment_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY comment_reports_comment_user_key (comment_id, user_id),
  KEY comment_reports_comment_idx (comment_id),
  CONSTRAINT {prefix}comment_reports_comment_fk FOREIGN KEY (comment_id) REFERENCES {{comments}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}comment_reports_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{watch_progresses}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  video_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  position_seconds INT NOT NULL DEFAULT 0,
  completed TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY watch_progresses_user_video_key (user_id, video_id),
  KEY watch_progresses_user_idx (user_id),
  CONSTRAINT {prefix}watch_progresses_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}watch_progresses_video_fk FOREIGN KEY (video_id) REFERENCES {{videos}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{reading_progresses}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  file_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  location VARCHAR(1000) NOT NULL,
  percent INT NOT NULL DEFAULT 0,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY reading_progresses_user_file_key (user_id, file_id),
  KEY reading_progresses_user_idx (user_id),
  CONSTRAINT {prefix}reading_progresses_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}reading_progresses_file_fk FOREIGN KEY (file_id) REFERENCES {{file_assets}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{reading_marks}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  file_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  kind VARCHAR(32) NOT NULL DEFAULT 'HIGHLIGHT',
  location VARCHAR(1000) NOT NULL,
  end_location VARCHAR(1000) NULL,
  excerpt TEXT NULL,
  note TEXT NULL,
  color VARCHAR(32) NOT NULL DEFAULT 'yellow',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY reading_marks_user_file_idx (user_id, file_id),
  KEY reading_marks_file_idx (file_id),
  CONSTRAINT {prefix}reading_marks_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}reading_marks_file_fk FOREIGN KEY (file_id) REFERENCES {{file_assets}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{api_keys}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(255) NOT NULL,
  hashed_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  prefix VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  scopes JSON NOT NULL,
  created_by_email VARCHAR(255) NOT NULL,
  expires_at DATETIME(3) NULL,
  revoked_at DATETIME(3) NULL,
  last_used_at DATETIME(3) NULL,
  window_started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  window_count INT NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY api_keys_hashed_key_key (hashed_key),
  KEY api_keys_revoked_idx (revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{audit_logs}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actor_email VARCHAR(255) NOT NULL,
  action VARCHAR(191) NOT NULL,
  entity_type VARCHAR(191) NOT NULL,
  entity_id VARCHAR(191) NULL,
  detail TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY audit_logs_created_idx (created_at),
  KEY audit_logs_actor_action_idx (actor_email, action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Plugin: the original's feature toggles, grown into the port's plugin
-- registry. `enabled` keeps its meaning (the site-wide default for a
-- feature). The query-monitor row keeps its special status: a row that is not
-- a plugin, excluded from the list.
CREATE TABLE IF NOT EXISTS {{plugins}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  slug VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  bundled TINYINT(1) NOT NULL DEFAULT 0,
  version VARCHAR(32) NULL,
  installed_version VARCHAR(32) NULL,
  deactivated_reason VARCHAR(64) NULL,
  deactivated_at DATETIME(3) NULL,
  deactivated_error TEXT NULL,
  notice_dismissed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY plugins_slug_key (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{plugin_category_overrides}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  plugin_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  category_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  enabled TINYINT(1) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY plugin_category_overrides_plugin_category_key (plugin_id, category_id),
  CONSTRAINT {prefix}plugin_category_overrides_plugin_fk FOREIGN KEY (plugin_id) REFERENCES {{plugins}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}plugin_category_overrides_category_fk FOREIGN KEY (category_id) REFERENCES {{categories}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{permission_groups}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(191) NOT NULL,
  description TEXT NULL,
  capabilities JSON NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY permission_groups_name_key (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{group_assignments}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  group_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  category_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  series_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY group_assignments_user_idx (user_id),
  CONSTRAINT {prefix}group_assignments_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}group_assignments_group_fk FOREIGN KEY (group_id) REFERENCES {{permission_groups}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}group_assignments_category_fk FOREIGN KEY (category_id) REFERENCES {{categories}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}group_assignments_series_fk FOREIGN KEY (series_id) REFERENCES {{series}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{ratings}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  series_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  video_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  value INT NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY ratings_user_series_key (user_id, series_id),
  UNIQUE KEY ratings_user_video_key (user_id, video_id),
  KEY ratings_series_idx (series_id),
  KEY ratings_video_idx (video_id),
  CONSTRAINT {prefix}ratings_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}ratings_series_fk FOREIGN KEY (series_id) REFERENCES {{series}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}ratings_video_fk FOREIGN KEY (video_id) REFERENCES {{videos}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{series_watch_laters}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  series_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY series_watch_laters_user_series_key (user_id, series_id),
  KEY series_watch_laters_series_idx (series_id),
  CONSTRAINT {prefix}series_watch_laters_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}series_watch_laters_series_fk FOREIGN KEY (series_id) REFERENCES {{series}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{category_watch_laters}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  category_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY category_watch_laters_user_category_key (user_id, category_id),
  KEY category_watch_laters_category_idx (category_id),
  CONSTRAINT {prefix}category_watch_laters_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}category_watch_laters_category_fk FOREIGN KEY (category_id) REFERENCES {{categories}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{video_watch_laters}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  video_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY video_watch_laters_user_video_key (user_id, video_id),
  KEY video_watch_laters_video_idx (video_id),
  CONSTRAINT {prefix}video_watch_laters_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}video_watch_laters_video_fk FOREIGN KEY (video_id) REFERENCES {{videos}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{push_subscriptions}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  endpoint VARCHAR(1000) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  endpoint_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  p256dh VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  auth VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  -- The endpoint is unique; indexed through its hash, since a push URL can
  -- be longer than an index prefix.
  UNIQUE KEY push_subscriptions_endpoint_key (endpoint_hash),
  KEY push_subscriptions_user_idx (user_id),
  CONSTRAINT {prefix}push_subscriptions_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{draft_revisions}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  entity_type VARCHAR(64) NOT NULL,
  entity_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  data JSON NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY draft_revisions_entity_key (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{webhooks}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  url VARCHAR(2000) NOT NULL,
  secret TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{announcements}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  message TEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  publish_at DATETIME(3) NULL,
  expires_at DATETIME(3) NULL,
  audience VARCHAR(32) NOT NULL DEFAULT 'ALL',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY announcements_active_idx (active, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{live_streams}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  embed_url VARCHAR(2000) NOT NULL,
  cover_image_url VARCHAR(2000) NULL,
  published TINYINT(1) NOT NULL DEFAULT 0,
  start_at DATETIME(3) NOT NULL,
  end_at DATETIME(3) NULL,
  chat_enabled TINYINT(1) NOT NULL DEFAULT 0,
  chat_slow_mode INT NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY live_streams_start_idx (start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{home_rows}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  type VARCHAR(32) NOT NULL,
  title VARCHAR(255) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  position INT NOT NULL DEFAULT 0,
  category_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  tag VARCHAR(191) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY home_rows_category_idx (category_id),
  CONSTRAINT {prefix}home_rows_category_fk FOREIGN KEY (category_id) REFERENCES {{categories}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{subscriptions}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  series_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  category_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  muted TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY subscriptions_user_series_key (user_id, series_id),
  UNIQUE KEY subscriptions_user_category_key (user_id, category_id),
  KEY subscriptions_series_idx (series_id),
  KEY subscriptions_category_idx (category_id),
  CONSTRAINT {prefix}subscriptions_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}subscriptions_series_fk FOREIGN KEY (series_id) REFERENCES {{series}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}subscriptions_category_fk FOREIGN KEY (category_id) REFERENCES {{categories}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{pending_notifications}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title VARCHAR(500) NOT NULL,
  body TEXT NOT NULL,
  url VARCHAR(2000) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY pending_notifications_user_idx (user_id),
  CONSTRAINT {prefix}pending_notifications_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{playlists}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title VARCHAR(255) NOT NULL,
  public TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY playlists_user_idx (user_id),
  CONSTRAINT {prefix}playlists_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{playlist_items}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  playlist_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  video_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  position INT NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY playlist_items_playlist_video_key (playlist_id, video_id),
  KEY playlist_items_video_idx (video_id),
  CONSTRAINT {prefix}playlist_items_playlist_fk FOREIGN KEY (playlist_id) REFERENCES {{playlists}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}playlist_items_video_fk FOREIGN KEY (video_id) REFERENCES {{videos}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{reactions}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  series_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  video_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  type VARCHAR(32) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY reactions_user_series_key (user_id, series_id),
  UNIQUE KEY reactions_user_video_key (user_id, video_id),
  KEY reactions_series_idx (series_id),
  KEY reactions_video_idx (video_id),
  CONSTRAINT {prefix}reactions_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}reactions_series_fk FOREIGN KEY (series_id) REFERENCES {{series}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}reactions_video_fk FOREIGN KEY (video_id) REFERENCES {{videos}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{view_events}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  series_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  video_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  ip_hash VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY view_events_series_created_idx (series_id, created_at),
  KEY view_events_video_created_idx (video_id, created_at),
  KEY view_events_created_idx (created_at),
  KEY view_events_ip_created_idx (ip_hash, created_at),
  CONSTRAINT {prefix}view_events_series_fk FOREIGN KEY (series_id) REFERENCES {{series}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}view_events_video_fk FOREIGN KEY (video_id) REFERENCES {{videos}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{hymn_lookups}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  file_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  number INT NULL,
  source VARCHAR(32) NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY hymn_lookups_created_idx (created_at),
  KEY hymn_lookups_file_number_created_idx (file_id, number, created_at),
  CONSTRAINT {prefix}hymn_lookups_file_fk FOREIGN KEY (file_id) REFERENCES {{file_assets}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{series_viewer_groups}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  series_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  group_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY series_viewer_groups_series_group_key (series_id, group_id),
  KEY series_viewer_groups_group_idx (group_id),
  CONSTRAINT {prefix}series_viewer_groups_series_fk FOREIGN KEY (series_id) REFERENCES {{series}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}series_viewer_groups_group_fk FOREIGN KEY (group_id) REFERENCES {{permission_groups}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{series_viewers}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  series_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY series_viewers_series_user_key (series_id, user_id),
  KEY series_viewers_user_idx (user_id),
  CONSTRAINT {prefix}series_viewers_series_fk FOREIGN KEY (series_id) REFERENCES {{series}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}series_viewers_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{video_viewer_groups}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  video_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  group_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY video_viewer_groups_video_group_key (video_id, group_id),
  KEY video_viewer_groups_group_idx (group_id),
  CONSTRAINT {prefix}video_viewer_groups_video_fk FOREIGN KEY (video_id) REFERENCES {{videos}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}video_viewer_groups_group_fk FOREIGN KEY (group_id) REFERENCES {{permission_groups}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{video_viewers}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  video_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY video_viewers_video_user_key (video_id, user_id),
  KEY video_viewers_user_idx (user_id),
  CONSTRAINT {prefix}video_viewers_video_fk FOREIGN KEY (video_id) REFERENCES {{videos}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}video_viewers_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{sermon_outline_answers}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  video_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  answers JSON NOT NULL,
  outline_version VARCHAR(128) NOT NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY sermon_outline_answers_user_video_key (user_id, video_id),
  KEY sermon_outline_answers_video_idx (video_id),
  CONSTRAINT {prefix}sermon_outline_answers_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}sermon_outline_answers_video_fk FOREIGN KEY (video_id) REFERENCES {{videos}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{sermon_notes}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  video_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  timestamp_seconds INT NOT NULL DEFAULT 0,
  body TEXT NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY sermon_notes_user_video_idx (user_id, video_id),
  CONSTRAINT {prefix}sermon_notes_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}sermon_notes_video_fk FOREIGN KEY (video_id) REFERENCES {{videos}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{slug_aliases}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  type VARCHAR(32) NOT NULL,
  old_slug VARCHAR(191) NOT NULL,
  target_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY slug_aliases_type_old_key (type, old_slug),
  KEY slug_aliases_target_idx (target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{share_links}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  token VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_by_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  series_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  video_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  visibility VARCHAR(32) NOT NULL DEFAULT 'PUBLIC',
  grants_access TINYINT(1) NOT NULL DEFAULT 0,
  note VARCHAR(500) NULL,
  -- scrypt$salt$key for imported links, a password_hash() string for new ones.
  password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
  failed_unlock_attempts INT NOT NULL DEFAULT 0,
  last_failed_unlock_at DATETIME(3) NULL,
  expires_at DATETIME(3) NULL,
  revoked_at DATETIME(3) NULL,
  view_count INT NOT NULL DEFAULT 0,
  last_viewed_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY share_links_token_key (token),
  KEY share_links_created_by_idx (created_by_id),
  KEY share_links_series_idx (series_id),
  KEY share_links_video_idx (video_id),
  CONSTRAINT {prefix}share_links_created_by_fk FOREIGN KEY (created_by_id) REFERENCES {{users}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}share_links_series_fk FOREIGN KEY (series_id) REFERENCES {{series}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}share_links_video_fk FOREIGN KEY (video_id) REFERENCES {{videos}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{share_link_recipients}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  share_link_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  email VARCHAR(255) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY share_link_recipients_link_email_key (share_link_id, email),
  CONSTRAINT {prefix}share_link_recipients_link_fk FOREIGN KEY (share_link_id) REFERENCES {{share_links}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{download_policies}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'singleton',
  platform VARCHAR(32) NOT NULL DEFAULT 'BOTH',
  audience VARCHAR(32) NOT NULL DEFAULT 'ALL_MEMBERS',
  max_device_gb INT NOT NULL DEFAULT 8,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{download_policy_groups}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  policy_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  group_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY download_policy_groups_policy_group_key (policy_id, group_id),
  KEY download_policy_groups_group_idx (group_id),
  CONSTRAINT {prefix}download_policy_groups_policy_fk FOREIGN KEY (policy_id) REFERENCES {{download_policies}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}download_policy_groups_group_fk FOREIGN KEY (group_id) REFERENCES {{permission_groups}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{download_policy_users}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  policy_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY download_policy_users_policy_user_key (policy_id, user_id),
  KEY download_policy_users_user_idx (user_id),
  CONSTRAINT {prefix}download_policy_users_policy_fk FOREIGN KEY (policy_id) REFERENCES {{download_policies}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}download_policy_users_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{auth_settings}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'singleton',
  guest_login_enabled TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{authorized_emails}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  email VARCHAR(255) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
  organization_exempt TINYINT(1) NOT NULL DEFAULT 0,
  note VARCHAR(500) NULL,
  added_by_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  added_by_email VARCHAR(255) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY authorized_emails_email_key (email),
  KEY authorized_emails_created_idx (created_at),
  CONSTRAINT {prefix}authorized_emails_added_by_fk FOREIGN KEY (added_by_id) REFERENCES {{users}} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{unauthorized_access_attempts}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  email VARCHAR(255) NULL,
  auth0_user_id VARCHAR(255) NULL,
  provider VARCHAR(64) NULL,
  attempt_type VARCHAR(32) NOT NULL,
  organization_member TINYINT(1) NOT NULL DEFAULT 0,
  email_authorized TINYINT(1) NOT NULL DEFAULT 0,
  reason VARCHAR(64) NOT NULL,
  detail TEXT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(500) NULL,
  notified_at DATETIME(3) NULL,
  reviewed_at DATETIME(3) NULL,
  reviewed_by_email VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY unauthorized_access_attempts_created_idx (created_at),
  KEY unauthorized_access_attempts_email_idx (email),
  KEY unauthorized_access_attempts_provider_idx (provider),
  KEY unauthorized_access_attempts_reason_idx (reason),
  KEY unauthorized_access_attempts_email_notified_idx (email, notified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{notifications}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title VARCHAR(500) NOT NULL,
  body TEXT NOT NULL,
  url VARCHAR(2000) NULL,
  read_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY notifications_user_created_idx (user_id, created_at),
  CONSTRAINT {prefix}notifications_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{brand_settings}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'singleton',
  name VARCHAR(255) NOT NULL DEFAULT 'Marine Team',
  short_name VARCHAR(255) NOT NULL DEFAULT 'Marine Team',
  brand VARCHAR(7) NOT NULL DEFAULT '#1a8fd1',
  brand_deep VARCHAR(7) NOT NULL DEFAULT '#0288d1',
  brand_light VARCHAR(7) NOT NULL DEFAULT '#4fc3f7',
  logo_url VARCHAR(2000) NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schedules --------------------------------------------------------------

CREATE TABLE IF NOT EXISTS {{schedules}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  slug VARCHAR(191) NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  icon VARCHAR(64) NOT NULL DEFAULT 'calendar',
  color VARCHAR(32) NOT NULL DEFAULT 'slate',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  display_order INT NOT NULL DEFAULT 0,
  source_type VARCHAR(32) NOT NULL DEFAULT 'WEB',
  deleted_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY schedules_slug_key (slug),
  KEY schedules_enabled_order_idx (enabled, display_order),
  KEY schedules_updated_idx (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{schedule_sources}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  schedule_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  type VARCHAR(32) NOT NULL,
  spreadsheet_id VARCHAR(191) NULL,
  sheet_name VARCHAR(191) NULL,
  `range` VARCHAR(64) NULL,
  format VARCHAR(32) NULL,
  parser_config JSON NOT NULL,
  sync_interval_minutes INT NOT NULL DEFAULT 60,
  last_synced_at DATETIME(3) NULL,
  last_sync_status VARCHAR(32) NOT NULL DEFAULT 'NEVER',
  last_sync_error TEXT NULL,
  last_sync_hash VARCHAR(128) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY schedule_sources_schedule_key (schedule_id),
  CONSTRAINT {prefix}schedule_sources_schedule_fk FOREIGN KEY (schedule_id) REFERENCES {{schedules}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{people}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  normalized_name VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  deleted_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY people_normalized_name_key (normalized_name),
  UNIQUE KEY people_user_key (user_id),
  KEY people_active_name_idx (active, display_name),
  KEY people_updated_idx (updated_at),
  CONSTRAINT {prefix}people_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{person_aliases}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  person_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  normalized_name VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY person_aliases_normalized_name_key (normalized_name),
  KEY person_aliases_person_idx (person_id),
  CONSTRAINT {prefix}person_aliases_person_fk FOREIGN KEY (person_id) REFERENCES {{people}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{calendar_events}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  schedule_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  external_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  date DATE NOT NULL,
  end_date DATE NULL,
  all_day TINYINT(1) NOT NULL DEFAULT 1,
  start_time VARCHAR(5) NULL,
  end_time VARCHAR(5) NULL,
  title VARCHAR(255) NULL,
  notes TEXT NULL,
  location VARCHAR(500) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'CONFIRMED',
  recurrence_rule VARCHAR(500) NULL,
  recurrence_end_date DATE NULL,
  parent_event_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  origin VARCHAR(32) NOT NULL DEFAULT 'WEB',
  source_row INT NULL,
  deleted_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY calendar_events_schedule_external_key (schedule_id, external_id),
  KEY calendar_events_schedule_date_idx (schedule_id, date),
  KEY calendar_events_date_idx (date),
  KEY calendar_events_updated_idx (updated_at),
  KEY calendar_events_parent_idx (parent_event_id),
  CONSTRAINT {prefix}calendar_events_schedule_fk FOREIGN KEY (schedule_id) REFERENCES {{schedules}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}calendar_events_parent_fk FOREIGN KEY (parent_event_id) REFERENCES {{calendar_events}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{calendar_event_people}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  person_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  role VARCHAR(191) NULL,
  position INT NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY calendar_event_people_event_person_key (event_id, person_id),
  KEY calendar_event_people_person_idx (person_id),
  KEY calendar_event_people_event_position_idx (event_id, position),
  CONSTRAINT {prefix}calendar_event_people_event_fk FOREIGN KEY (event_id) REFERENCES {{calendar_events}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}calendar_event_people_person_fk FOREIGN KEY (person_id) REFERENCES {{people}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Events -----------------------------------------------------------------

CREATE TABLE IF NOT EXISTS {{event_series}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  rule VARCHAR(500) NOT NULL,
  time_zone VARCHAR(64) NOT NULL DEFAULT 'UTC',
  start_date DATE NOT NULL,
  start_time VARCHAR(5) NULL,
  duration_minutes INT NULL,
  all_day TINYINT(1) NOT NULL DEFAULT 0,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  location VARCHAR(500) NULL,
  published TINYINT(1) NOT NULL DEFAULT 0,
  member_only TINYINT(1) NOT NULL DEFAULT 0,
  registration TINYINT(1) NOT NULL DEFAULT 0,
  capacity INT NULL,
  waitlist TINYINT(1) NOT NULL DEFAULT 1,
  max_guests INT NOT NULL DEFAULT 0,
  opens_days_before INT NULL,
  closes_days_before INT NULL,
  generated_through DATE NULL,
  excluded_dates JSON NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY event_series_generated_idx (generated_through)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{events}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  slug VARCHAR(191) NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  location VARCHAR(500) NULL,
  starts_at DATETIME(3) NOT NULL,
  ends_at DATETIME(3) NULL,
  all_day TINYINT(1) NOT NULL DEFAULT 0,
  published TINYINT(1) NOT NULL DEFAULT 0,
  member_only TINYINT(1) NOT NULL DEFAULT 0,
  registration TINYINT(1) NOT NULL DEFAULT 0,
  capacity INT NULL,
  waitlist TINYINT(1) NOT NULL DEFAULT 1,
  opens_at DATETIME(3) NULL,
  closes_at DATETIME(3) NULL,
  max_guests INT NOT NULL DEFAULT 0,
  series_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  occurrence_date DATE NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY events_slug_key (slug),
  UNIQUE KEY events_series_occurrence_key (series_id, occurrence_date),
  KEY events_published_starts_idx (published, starts_at),
  KEY events_starts_idx (starts_at),
  CONSTRAINT {prefix}events_series_fk FOREIGN KEY (series_id) REFERENCES {{event_series}} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{event_registrations}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(64) NULL,
  guests INT NOT NULL DEFAULT 0,
  note TEXT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'GOING',
  promoted_at DATETIME(3) NULL,
  cancelled_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY event_registrations_event_user_key (event_id, user_id),
  KEY event_registrations_event_status_created_idx (event_id, status, created_at),
  KEY event_registrations_user_idx (user_id),
  CONSTRAINT {prefix}event_registrations_event_fk FOREIGN KEY (event_id) REFERENCES {{events}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}event_registrations_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Forms ------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS {{forms}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  slug VARCHAR(191) NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  published TINYINT(1) NOT NULL DEFAULT 0,
  member_only TINYINT(1) NOT NULL DEFAULT 0,
  multiple TINYINT(1) NOT NULL DEFAULT 1,
  confirmation TEXT NULL,
  notify_emails VARCHAR(2000) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY forms_slug_key (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{form_fields}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  form_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  label VARCHAR(500) NOT NULL,
  type VARCHAR(32) NOT NULL DEFAULT 'TEXT',
  help VARCHAR(1000) NULL,
  required TINYINT(1) NOT NULL DEFAULT 0,
  options TEXT NULL,
  position INT NOT NULL DEFAULT 0,
  deleted_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY form_fields_form_position_idx (form_id, position),
  CONSTRAINT {prefix}form_fields_form_fk FOREIGN KEY (form_id) REFERENCES {{forms}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{form_submissions}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  form_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  handled_at DATETIME(3) NULL,
  handled_by VARCHAR(255) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY form_submissions_form_created_idx (form_id, created_at),
  KEY form_submissions_user_idx (user_id),
  CONSTRAINT {prefix}form_submissions_form_fk FOREIGN KEY (form_id) REFERENCES {{forms}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}form_submissions_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{form_answers}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  submission_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  field_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  value TEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY form_answers_submission_field_key (submission_id, field_id),
  KEY form_answers_field_idx (field_id),
  CONSTRAINT {prefix}form_answers_submission_fk FOREIGN KEY (submission_id) REFERENCES {{form_submissions}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}form_answers_field_fk FOREIGN KEY (field_id) REFERENCES {{form_fields}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Prayer -----------------------------------------------------------------

CREATE TABLE IF NOT EXISTS {{prayer_requests}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  name VARCHAR(255) NULL,
  body TEXT NOT NULL,
  anonymous TINYINT(1) NOT NULL DEFAULT 0,
  visibility VARCHAR(32) NOT NULL DEFAULT 'MEMBERS',
  status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
  answered_note TEXT NULL,
  answered_at DATETIME(3) NULL,
  moderated_by VARCHAR(255) NULL,
  moderated_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY prayer_requests_status_created_idx (status, created_at),
  KEY prayer_requests_user_idx (user_id),
  CONSTRAINT {prefix}prayer_requests_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{prayer_intercessions}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY prayer_intercessions_request_user_key (request_id, user_id),
  KEY prayer_intercessions_request_idx (request_id),
  CONSTRAINT {prefix}prayer_intercessions_request_fk FOREIGN KEY (request_id) REFERENCES {{prayer_requests}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}prayer_intercessions_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Small groups -----------------------------------------------------------

CREATE TABLE IF NOT EXISTS {{small_groups}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  slug VARCHAR(191) NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  meets_when VARCHAR(255) NULL,
  area VARCHAR(255) NULL,
  address VARCHAR(1000) NULL,
  published TINYINT(1) NOT NULL DEFAULT 0,
  open_to_join TINYINT(1) NOT NULL DEFAULT 1,
  capacity INT NULL,
  waitlist TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY small_groups_slug_key (slug),
  KEY small_groups_published_idx (published)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{small_group_members}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  group_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  role VARCHAR(32) NOT NULL DEFAULT 'MEMBER',
  status VARCHAR(32) NOT NULL DEFAULT 'REQUESTED',
  muted TINYINT(1) NOT NULL DEFAULT 0,
  note TEXT NULL,
  responded_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY small_group_members_group_user_key (group_id, user_id),
  KEY small_group_members_user_status_idx (user_id, status),
  KEY small_group_members_group_status_idx (group_id, status),
  CONSTRAINT {prefix}small_group_members_group_fk FOREIGN KEY (group_id) REFERENCES {{small_groups}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}small_group_members_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{group_messages}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  group_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  author_name VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  hidden TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY group_messages_group_id_idx (group_id, id),
  KEY group_messages_user_idx (user_id),
  CONSTRAINT {prefix}group_messages_group_fk FOREIGN KEY (group_id) REFERENCES {{small_groups}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}group_messages_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{discussion_guides}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  slug VARCHAR(191) NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  series_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  video_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  published TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY discussion_guides_slug_key (slug),
  KEY discussion_guides_published_updated_idx (published, updated_at),
  KEY discussion_guides_series_idx (series_id),
  KEY discussion_guides_video_idx (video_id),
  CONSTRAINT {prefix}discussion_guides_series_fk FOREIGN KEY (series_id) REFERENCES {{series}} (id) ON DELETE SET NULL,
  CONSTRAINT {prefix}discussion_guides_video_fk FOREIGN KEY (video_id) REFERENCES {{videos}} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{discussion_guide_items}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  guide_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  kind VARCHAR(32) NOT NULL DEFAULT 'QUESTION',
  body TEXT NOT NULL,
  reference VARCHAR(255) NULL,
  position INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY discussion_guide_items_guide_position_idx (guide_id, position),
  CONSTRAINT {prefix}discussion_guide_items_guide_fk FOREIGN KEY (guide_id) REFERENCES {{discussion_guides}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{small_group_meetings}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  group_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  date DATE NOT NULL,
  topic VARCHAR(500) NULL,
  visitor_count INT NOT NULL DEFAULT 0,
  cancelled TINYINT(1) NOT NULL DEFAULT 0,
  leader_notes TEXT NULL,
  guide_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  recorded_by_email VARCHAR(255) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY small_group_meetings_group_date_key (group_id, date),
  KEY small_group_meetings_guide_idx (guide_id),
  CONSTRAINT {prefix}small_group_meetings_group_fk FOREIGN KEY (group_id) REFERENCES {{small_groups}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}small_group_meetings_guide_fk FOREIGN KEY (guide_id) REFERENCES {{discussion_guides}} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{group_attendances}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  meeting_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'PRESENT',
  note VARCHAR(500) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY group_attendances_meeting_user_key (meeting_id, user_id),
  KEY group_attendances_user_idx (user_id),
  CONSTRAINT {prefix}group_attendances_meeting_fk FOREIGN KEY (meeting_id) REFERENCES {{small_group_meetings}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}group_attendances_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Broadcasts -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS {{broadcasts}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  subject VARCHAR(500) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  channels JSON NOT NULL,
  audience VARCHAR(32) NOT NULL DEFAULT 'EVERYONE',
  audience_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  audience_name VARCHAR(255) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'DRAFT',
  created_by VARCHAR(255) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  sent_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY broadcasts_status_created_idx (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{broadcast_recipients}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  broadcast_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  channel VARCHAR(32) NOT NULL,
  address VARCHAR(255) NOT NULL,
  name VARCHAR(255) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
  error TEXT NULL,
  sent_at DATETIME(3) NULL,
  -- The port's: what the provider called the message, and whether a
  -- delivery receipt said it arrived (SMS "reached" rather than "sent").
  provider VARCHAR(32) NULL,
  provider_message_id VARCHAR(191) NULL,
  delivery_status VARCHAR(32) NULL,
  delivered_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY broadcast_recipients_broadcast_channel_address_key (broadcast_id, channel, address),
  KEY broadcast_recipients_broadcast_status_idx (broadcast_id, status),
  KEY broadcast_recipients_provider_message_idx (provider, provider_message_id),
  CONSTRAINT {prefix}broadcast_recipients_broadcast_fk FOREIGN KEY (broadcast_id) REFERENCES {{broadcasts}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}broadcast_recipients_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Live chat --------------------------------------------------------------

CREATE TABLE IF NOT EXISTS {{live_chat_messages}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  stream_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  author_name VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  hidden TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY live_chat_messages_stream_id_idx (stream_id, id),
  KEY live_chat_messages_user_idx (user_id),
  CONSTRAINT {prefix}live_chat_messages_stream_fk FOREIGN KEY (stream_id) REFERENCES {{live_streams}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}live_chat_messages_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{live_chat_mutes}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  stream_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  muted_by VARCHAR(255) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY live_chat_mutes_stream_user_key (stream_id, user_id),
  CONSTRAINT {prefix}live_chat_mutes_stream_fk FOREIGN KEY (stream_id) REFERENCES {{live_streams}} (id) ON DELETE CASCADE,
  CONSTRAINT {prefix}live_chat_mutes_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Television -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS {{tv_devices}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  device_code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
  device_name VARCHAR(255) NOT NULL,
  device_kind VARCHAR(64) NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  expires_at DATETIME(3) NOT NULL,
  approved_at DATETIME(3) NULL,
  linked_at DATETIME(3) NULL,
  last_seen_at DATETIME(3) NULL,
  revoked_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY tv_devices_user_code_key (user_code),
  UNIQUE KEY tv_devices_device_code_hash_key (device_code_hash),
  UNIQUE KEY tv_devices_token_hash_key (token_hash),
  KEY tv_devices_user_status_idx (user_id, status),
  KEY tv_devices_expires_idx (expires_at),
  CONSTRAINT {prefix}tv_devices_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The port's own tables ---------------------------------------------------

-- Sessions. id_hash is SHA-256 of the cookie's 32 random bytes: a database
-- read never yields a usable session.
CREATE TABLE IF NOT EXISTS {{sessions}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  id_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  data JSON NOT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME(3) NOT NULL,
  last_seen_at DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY sessions_id_hash_key (id_hash),
  KEY sessions_user_idx (user_id),
  KEY sessions_last_seen_idx (last_seen_at),
  CONSTRAINT {prefix}sessions_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per service slot: which provider is active, and its settings with
-- secrets encrypted under app_key. Past providers keep their row per slot
-- (active = 0) so the videos they hold keep playing.
CREATE TABLE IF NOT EXISTS {{services}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  slot VARCHAR(32) NOT NULL,
  provider VARCHAR(64) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 0,
  config JSON NOT NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  updated_by VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY services_slot_provider_key (slot, provider),
  KEY services_slot_active_idx (slot, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Site settings that are not a service: bootstrap administrators, the
-- authorization mode, the cron token, feature groups (Web Push, transcription,
-- Google Sheets, video import). Secret values are encrypted like services'.
CREATE TABLE IF NOT EXISTS {{settings}} (
  name VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  value JSON NOT NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{jobs}} (
  name VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  interval_seconds INT NOT NULL,
  next_run_at DATETIME(3) NOT NULL,
  last_run_at DATETIME(3) NULL,
  last_status VARCHAR(32) NULL,
  last_error TEXT NULL,
  lock_until DATETIME(3) NULL,
  lock_owner VARCHAR(64) NULL,
  PRIMARY KEY (name),
  KEY jobs_next_run_idx (next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every email the app tried to send: on shared hosting this log is the only
-- way to learn a message never left.
CREATE TABLE IF NOT EXISTS {{email_log}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  to_address VARCHAR(255) NOT NULL,
  subject VARCHAR(500) NOT NULL,
  provider VARCHAR(64) NOT NULL,
  status VARCHAR(32) NOT NULL,
  provider_message_id VARCHAR(255) NULL,
  error TEXT NULL,
  -- Kept so the admin's resend button can send the same message again.
  text_body MEDIUMTEXT NULL,
  html_body MEDIUMTEXT NULL,
  reply_to VARCHAR(255) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY email_log_created_idx (created_at),
  KEY email_log_to_idx (to_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-use, hashed tokens: password reset, magic link, email verification.
CREATE TABLE IF NOT EXISTS {{auth_tokens}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  purpose VARCHAR(32) NOT NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  -- For an email change: the address being verified.
  email VARCHAR(255) NULL,
  expires_at DATETIME(3) NOT NULL,
  used_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY auth_tokens_hash_key (token_hash),
  KEY auth_tokens_user_purpose_idx (user_id, purpose),
  CONSTRAINT {prefix}auth_tokens_user_fk FOREIGN KEY (user_id) REFERENCES {{users}} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Throttles for sign-in, reset, magic link, share-link unlock, television
-- pairing, API keys and the public write endpoints: per key (an account, an
-- address, a hash of an IP), counted in the database because there is no
-- process state on shared hosting to count in.
CREATE TABLE IF NOT EXISTS {{rate_limits}} (
  bucket VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  window_started_at DATETIME(3) NOT NULL,
  hits INT NOT NULL DEFAULT 0,
  failures INT NOT NULL DEFAULT 0,
  blocked_until DATETIME(3) NULL,
  PRIMARY KEY (bucket),
  KEY rate_limits_window_idx (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chunked uploads through PHP: one row per upload in progress.
CREATE TABLE IF NOT EXISTS {{uploads}} (
  id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  purpose VARCHAR(32) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  size_bytes BIGINT NOT NULL,
  received_bytes BIGINT NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'OPEN',
  meta JSON NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY uploads_created_idx (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
