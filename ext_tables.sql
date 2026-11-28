# Columns whose default is not 0/empty: the schema derived from the TCA ignores `default` for numbers,
# so rows that exist when a column is added would otherwise get 0 (focal point in the top-left corner).
CREATE TABLE tt_content (
	vo_focus_x int(10) unsigned DEFAULT '50' NOT NULL,
	vo_focus_y int(10) unsigned DEFAULT '50' NOT NULL,
	vo_scene_count int(10) unsigned DEFAULT '5' NOT NULL,
);
