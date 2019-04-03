<?php


class SecondPassReview extends AbstractMatecatMigration {

    public $sql_up = [
            "ALTER TABLE segment_translation_versions DROP COLUMN `is_review`" ,

            "ALTER TABLE segment_translation_events ADD COLUMN create_date DATETIME" ,

            "ALTER TABLE qa_chunk_reviews CHANGE `num_errors` `source_page` int(11); ",
            "UPDATE qa_chunk_reviews SET source_page = 2 ; ",
            "ALTER TABLE qa_chunk_reviews DROP INDEX `id_job_password` ; ",
            "CREATE UNIQUE INDEX `job_pw_source_page` ON qa_chunk_reviews (`id_job`, `password`, `source_page`);",
    ] ;

    public $sql_down = [
            " ALTER TABLE segment_translation_versions ADD COLUMN is_review tinyint(4) ",

            "ALTER TABLE segment_translation_events DROP COLUMN `create_date`" ,

            " ALTER TABLE qa_chunk_reviews CHANGE `source_page` `num_errors` int(11); ",
            " UPDATE qa_chunk_reviews SET num_errors = 0 ; ",
            " CREATE UNIQUE INDEX `id_job_password` ON qa_chunk_reviews (`id_job`, `password`); ",
            " ALTER TABLE qa_chunk_reviews DROP INDEX `job_pw_source_page` ;",
    ] ;

}
