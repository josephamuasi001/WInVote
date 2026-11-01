USE winvote_dev;

ALTER TABLE events
  ADD COLUMN cover_image_key VARCHAR(40) NULL;

ALTER TABLE categories
  ADD COLUMN image_key VARCHAR(40) NULL;

ALTER TABLE nominations
  ADD COLUMN profile_image_key VARCHAR(40) NULL;
