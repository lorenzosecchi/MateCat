<?php
/**
 * Created by PhpStorm.
 * User: fregini
 * Date: 08/12/2016
 * Time: 09:52
 */

namespace Users;


class MetadataModel {

    /**
     * @var array
     */
    protected $metadata ;

    /**
     * @var \Users_UserStruct
     */
    protected $user ;

    public function __construct($user, $metadata)
    {
        $this->user = $user ;
        $this->metadata = $metadata ;
    }

    public function save() {
        // validate
        $features = new \FeatureSet() ;

        $metadataFilters = $features->filter('filterMetadataFilters', array() ) ;

        $this->metadata = filter_var_array($this->metadata, $metadataFilters) ;

        $dao = new MetadataDao() ;

        foreach( $this->metadata as $key => $value ) {
            $dao->set($this->user->uid, $key, $value);
        }

    }

}