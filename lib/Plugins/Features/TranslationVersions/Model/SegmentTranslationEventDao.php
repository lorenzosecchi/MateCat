<?php
/**
 * Created by PhpStorm.
 * User: fregini
 * Date: 07/02/2018
 * Time: 17:20
 */

namespace Features\TranslationVersions\Model ;

class SegmentTranslationEventDao extends \DataAccess_AbstractDao {

    const TABLE       = "segment_translation_events";
    const STRUCT_TYPE = "\Features\Dqf\Model\SegmentTranslationEventStruct";

    protected static $auto_increment_field = [ 'id' ];
    protected static $primary_keys         = [ 'id' ];

    public function insertForPropagation($propagatedIds, SegmentTranslationEventStruct $struct) {

        $sql = "INSERT INTO " . self::TABLE . " ( id_job, id_segment, uid ,
                status, version_number, source_page, create_date  )
                SELECT :id_job, st.id_segment, :uid, st.status, st.version_number, :source_page, :create_date
                FROM segment_translations st WHERE st.id_segment IN ( " .
                implode(',', $propagatedIds ) . " ) " ;

        $conn = $this->getConnection()->getConnection() ;
        $stmt = $conn->prepare( $sql );

        $struct->setTimestamp('create_date', time() );

        $stmt->execute( $struct->toArray(['id_job', 'uid', 'source_page', 'create_date']) ) ;

        return $stmt->rowCount() ;
    }

}