<?php
/**
 * Created by PhpStorm.
 * User: fregini
 * Date: 07/11/2016
 * Time: 15:28
 */

namespace ConnectedServices\GDrive;

use ConnectedServices\ConnectedServiceDao;
use ConnectedServices\ConnectedServiceStruct;
use ConversionHandler;
use DirectoryIterator;
use Exception;
use FilesStorage\AbstractFilesStorage;
use FilesStorage\FilesStorageFactory;
use FilesStorage\S3FilesStorage;
use INIT;
use Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Users_UserDao;
use Utils;

/**
 * Class Session
 * @package ConnectedServices\GDrive
 */
class Session {

    const FILE_LIST             = 'gdriveFileList';
    const FILE_NAME             = 'fileName';
    const FILE_HASH             = 'fileHash';
    const CONNNECTED_SERVICE_ID = 'connectedServiceId';

    protected $guid;
    protected $source_lang;
    protected $target_lang;
    protected $seg_rule;

    protected $session;

    /**
     * @var \Google_Service_Drive
     */
    protected $service;
    protected $token;

    /**
     * @var ConnectedServiceStruct
     */
    protected $serviceStruct;

    /**
     * @var \Users_UserStruct
     */
    protected $user;

    /**
     * @var AbstractFilesStorage
     */
    protected $files_storage;

    /**
     * MUST NOT TO BE CALLED FROM THE cli
     *
     * Session constructor.
     * @throws Exception
     */
    public function __construct() {
        if ( !isset( $_SESSION[ 'uid' ] ) ) {
            throw new \Exception( 'Cannot instantiate session for unlogged user' );
        }

        $this->session = &$_SESSION;

        $this->files_storage = FilesStorageFactory::create();
    }

    /**
     * This class overrides a not existent super global when called by CLI
     *
     * @param $session
     *
     * @return Session
     */
    public static function getInstanceForCLI( $session ) {
        if ( PHP_SAPI != 'cli' ) {
            throw new \RuntimeException( "This method MUST be called by CLI." );
        }
        $_SESSION = $session;

        return new self();
    }

    /**
     * @param $newSourceLang
     * @param $originalSourceLang
     *
     * @return bool
     * @throws \Exception
     */
    public function changeSourceLanguage( $newSourceLang, $originalSourceLang ) {
        $fileList  = $this->session[ self::FILE_LIST ];
        $success   = true;

        $this->renameTheFileMap($newSourceLang, $originalSourceLang);

        foreach ( $fileList as $fileId => $file ) {

            if ( $success ) {

                $fileHash = $file[ self::FILE_HASH ];

                if ( $newSourceLang !== $originalSourceLang ) {

                    $originalCacheFileDir = $this->getCacheFileDir( $file, $originalSourceLang );
                    $newCacheFileDir = $this->getCacheFileDir( $file, $newSourceLang );

                    if(AbstractFilesStorage::isOnS3()){

                        // copy orig and cache\INIT::$UPLOAD_REPOSITORY folder
                        $s3Client = S3FilesStorage::getStaticS3Client();
                        $copyOrig = $s3Client->copyFolder([
                                'source_bucket' => \INIT::$AWS_STORAGE_BASE_BUCKET,
                                'source_folder' => $originalCacheFileDir.'/orig',
                                'target_folder' => $newCacheFileDir.'/orig',
                                'delete_source' => false,
                        ]);

                        $copyWork = $s3Client->copyFolder([
                                'source_bucket' => \INIT::$AWS_STORAGE_BASE_BUCKET,
                                'source_folder' => $originalCacheFileDir.'/work',
                                'target_folder' => $newCacheFileDir.'/work',
                                'delete_source' => false,
                        ]);

                        if($copyOrig and $copyWork){
                            $renameDirSuccess = true;
                            $renameFileRefSuccess = true;
                        }
                    } else {
                        $renameDirSuccess = rename( $originalCacheFileDir, $newCacheFileDir );

                        $uploadDir = $this->getUploadDir();

                        $originalUploadRefFile = $uploadDir . DIRECTORY_SEPARATOR . $fileHash . '|' . $originalSourceLang;
                        $newUploadRefFile      = $uploadDir . DIRECTORY_SEPARATOR . $fileHash . '|' . $newSourceLang;

                        $renameFileRefSuccess = rename( $originalUploadRefFile, $newUploadRefFile );
                    }

                    if ( !$renameDirSuccess || !$renameFileRefSuccess ) {
                        Log::doJsonLog( 'Error when moving cache file dir to ' . $newCacheFileDir );
                        $success = false;
                    }
                }
            }
        }

        return $success;
    }

    /**
     * Rename the filemap in session folder stored on filesystem
     *
     * ----------------------------------------------------------------------
     *
     * Example:
     *
     * 2344e5918dcff468b4362d79cb16b0039c77d608|af-ZA ---> 2344e5918dcff468b4362d79cb16b0039c77d608|it-IT
     *
     * @param $originalSourceLang
     * @param $newSourceLang
     */
    private function renameTheFileMap($newSourceLang, $originalSourceLang) {
        $uploadDir = \INIT::$UPLOAD_REPOSITORY . DIRECTORY_SEPARATOR . $this->session['upload_session'];

        /** @var DirectoryIterator $item */
        foreach (
                $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator( $uploadDir, \RecursiveDirectoryIterator::SKIP_DOTS ),
                        \RecursiveIteratorIterator::SELF_FIRST ) as $item
        ) {
            $originalSourceLangMarker = '|' . $originalSourceLang;
            $newSourceLangMarker = '|' . $newSourceLang;

            if(AbstractFilesStorage::fileEndsWith( $item->getBasename(), $originalSourceLangMarker )){
                $newSourceLangFileMap = str_replace($originalSourceLangMarker, $newSourceLangMarker, $item->getBasename());
                rename($item->getBasename(), $newSourceLangFileMap);
            }
        }
    }

    /**
     * TODO: this is presentation layer, consider removing from here.
     *
     * @return array
     */
    public function getFileStructureForJsonOutput() {
        $response = [];

        foreach ( $this->session[ self::FILE_LIST ] as $fileId => $file ) {

            $fileName = $file[ self::FILE_NAME ];

            if(AbstractFilesStorage::isOnS3()){
                $path = $this->getGDriveFilePathForS3( $file );
                $s3Client = S3FilesStorage::getStaticS3Client();
                $s3 = $s3Client->getItem( [
                        'bucket' => S3FilesStorage::getFilesStorageBucket(),
                        'key' => $path
                    ]
                );

                $mime = include __DIR__.'/../../../Utils/Mime2Extension.php';
                $response[ 'files' ][] = [
                        'fileId'        => $fileId,
                        'fileName'      => $fileName,
                        'fileSize'      => $s3['ContentLength'],
                        'fileExtension' => $mime[$s3['ContentType']][0]
                ];

            } else {
                $path = $this->getGDriveFilePath( $file );
                if ( file_exists( $path ) !== false ) {
                    $fileSize = filesize( $path );

                    $fileExtension = pathinfo( $fileName, PATHINFO_EXTENSION );

                    $response[ 'files' ][] = [
                            'fileId'        => $fileId,
                            'fileName'      => $fileName,
                            'fileSize'      => $fileSize,
                            'fileExtension' => $fileExtension
                    ];
                } else {
                    unset( $this->session[ self::FILE_LIST ][ $fileId ] );
                }
            }
        }

        return $response;
    }

    /**
     * MUST NOT TO BE CALLED FROM THE cli
     */
    public static function cleanupSessionFiles() {
        if ( self::sessionHasFiles( $_SESSION ) ) {
            unset( $_SESSION[ self::FILE_LIST ] );
        }
    }

    public function getToken() {
        if ( is_null( $this->token ) ) {
            $this->token = $this->getTokenByUser( $this->__getUser() );
        }

        return $this->token;
    }

    private function __getUser() {
        if ( is_null( $this->user ) ) {
            $dao        = new Users_UserDao();
            $this->user = $dao->getByUid( $this->session[ 'uid' ] );
        }

        return $this->user;
    }

    /**
     * @param \Users_UserStruct $user
     *
     * @return FALSE|string
     *
     */
    public function getTokenByUser( \Users_UserStruct $user ) {
        $serviceDao          = new ConnectedServiceDao();
        $this->serviceStruct = $serviceDao->findDefaultServiceByUserAndName( $user, 'gdrive' );

        if ( !$this->serviceStruct ) {
            return false;
        } else {
            return $this->serviceStruct->getDecodedOauthAccessToken();
        }
    }

    /**
     * Adds files to the session variables.
     *
     * @param $fileId
     * @param $fileName
     * @param $fileHash
     *
     */

    public function addFiles( $fileId, $fileName, $fileHash ) {

        if ( !isset( $this->session[ self::FILE_LIST ] )
                || !is_array( $this->session[ self::FILE_LIST ] ) ) {

            $this->session[ self::FILE_LIST ] = [];
        }

        $this->session[ self::FILE_LIST ][ $fileId ] = [
                self::FILE_NAME             => $fileName,
                self::FILE_HASH             => $fileHash,
                self::CONNNECTED_SERVICE_ID => $this->serviceStruct->id,
        ];
    }


    public function hasFiles() {
        return
                isset( $this->session[ self::FILE_LIST ] )
                && !empty( $this->session[ self::FILE_LIST ] );
    }

    /**
     * @param $session
     *
     * @return bool
     * @deprecated use the non static version
     */
    public static function sessionHasFiles( $session ) {
        if ( isset( $session[ self::FILE_LIST ] )
                && !empty( $session[ self::FILE_LIST ] ) ) {
            return true;
        }

        return false;
    }

    public function findFileIdByName( $fileName ) {
        if ( $this->hasFiles() ) {
            foreach ( $this->session[ self::FILE_LIST ] as $singleFileId => $file ) {
                if ( $file[ self::FILE_NAME ] === $fileName ) {
                    return $singleFileId;
                }
            }
        }

        return null;
    }

    /**
     * Gets the service if token is available.
     * If token is not found in database, then returns FALSE ;
     *
     * Memoizes the response.
     *
     * Returned token may still be expired.
     *
     * @return \Google_Service_Drive|FALSE
     */
    public function getService() {
        if ( is_null( $this->service ) ) {

            $token = $this->getToken();

            if ( $token ) {
                $this->service = RemoteFileService::getService( $token );
            } else {
                $this->service = false;
            }
        }

        return $this->service;
    }

    public function buildRemoteFile() {
        if ( !$this->getToken() ) {
            throw  new Exception( 'Cannot build RemoteFile without a token' );
        }

        return new RemoteFileService( $this->token );

    }

    public function clearFiles() {
        unset( $this->session[ self::FILE_LIST ] );
    }

    public function removeFile( $fileId ) {
        $success = false;

        if ( isset( $this->session[ self::FILE_LIST ][ $fileId ] ) ) {
            $file      = $this->session[ self::FILE_LIST ][ $fileId ];
            $pathCache = $this->getCacheFileDir( $file );

            if(S3FilesStorage::isOnS3()){
                $s3Client = S3FilesStorage::getStaticS3Client();
                $s3Client->deleteFolder( [
                        'bucket' => S3FilesStorage::getFilesStorageBucket(),
                        'key' => $pathCache
                    ]
                );
            } else {
                $this->deleteDirectory( $pathCache );
            }

            unset( $this->session[ self::FILE_LIST ] [ $fileId ] );

            Log::doJsonLog( 'File ' . $fileId . ' removed.' );

            $success = true;
        }

        return $success;


    }

    public function removeAllFiles() {
        foreach ( $this->session[ self::FILE_LIST ] as $singleFileId => $file ) {
            $this->removeFile( $singleFileId );
        }

        unset( $this->session[ self::FILE_LIST ] );
    }


    /**
     * TODO: move to something generic and static
     *
     * @param $dir
     */
    private function deleteDirectory( $dir ) {
        $it    = new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS );
        $files = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );

        foreach ( $files as $file ) {
            if ( $file->isDir() ) {
                rmdir( $file->getRealPath() );
            } else {
                unlink( $file->getRealPath() );
            }
        }

        rmdir( $dir );
    }

    private function getUploadDir() {
        return \INIT::$UPLOAD_REPOSITORY . DIRECTORY_SEPARATOR . filter_input( INPUT_COOKIE, 'upload_session' );
    }

    /**
     * @param        $file
     * @param string $lang
     *
     * @return string
     */
    private function getCacheFileDir( $file, $lang = '' ) {
        $sourceLang = $this->session[ \Constants::SESSION_ACTUAL_SOURCE_LANG ];

        if ( $lang !== '' ) {
            $sourceLang = $lang;
        }

        $fileHash = $file[ self::FILE_HASH ];

        $fs          = $this->files_storage;
        $cacheTreeAr = $fs::composeCachePath( $fileHash );

        $cacheTree = implode( DIRECTORY_SEPARATOR, $cacheTreeAr );

        if(AbstractFilesStorage::isOnS3()){
            return S3FilesStorage::CACHE_PACKAGE_FOLDER . DIRECTORY_SEPARATOR . $cacheTree . S3FilesStorage::OBJECTS_SAFE_DELIMITER . $sourceLang;
        }

        return \INIT::$CACHE_REPOSITORY . DIRECTORY_SEPARATOR . $cacheTree . "|" . $sourceLang;
    }

    /**
     * @param $file
     *
     * @return string
     */
    private function getGDriveFilePath( $file ) {

        $fileName = $file[ self::FILE_NAME ];
        $cacheFileDir = $this->getCacheFileDir( $file );

        return $cacheFileDir . DIRECTORY_SEPARATOR . "package" . DIRECTORY_SEPARATOR . "orig" . DIRECTORY_SEPARATOR . $fileName;
    }

    /**
     * @param $file
     *
     * @return string
     */
    private function getGDriveFilePathForS3( $file ) {

        $fileName = $file[ self::FILE_NAME ];
        $cacheFileDir = $this->getCacheFileDir( $file );

        return $cacheFileDir . DIRECTORY_SEPARATOR . "orig" . DIRECTORY_SEPARATOR . $fileName;
    }

    /**
     * @param $guid
     * @param $source_lang
     * @param $target_lang
     * @param $seg_rule
     */
    public function setConversionParams( $guid, $source_lang, $target_lang, $seg_rule ) {
        $this->guid        = $guid;
        $this->source_lang = $source_lang;
        $this->target_lang = $target_lang;
        $this->seg_rule    = $seg_rule;
    }

    /**
     * @param $fileId
     * @param $remoteFileId
     */
    public function createRemoteFile( $fileId, $remoteFileId ) {
        $this->getService();
        \RemoteFiles_RemoteFileDao::insert( $fileId, 0, $remoteFileId, $this->serviceStruct->id, 1 );
    }

    /**
     *
     * Creates copies of the original remote file there the translation will be saved.
     *
     * @param $id_file
     * @param $id_job
     */
    public function createRemoteCopiesWhereToSaveTranslation( $id_file, $id_job ) {
        $this->getService();

        $listRemoteFiles = \RemoteFiles_RemoteFileDao::getByFileId( $id_file, 1 );
        $remoteFile      = $listRemoteFiles[ 0 ];

        $gdriveFile = $this->service->files->get( $remoteFile->remote_id );

        $fileTitle = $gdriveFile->getTitle();

        $job                 = \Jobs_JobDao::getById( $id_job )[ 0 ];
        $translatedFileTitle = $fileTitle . ' - ' . $job->target;

        $remoteFileService = $this->buildRemoteFile();
        $copiedFile        = $remoteFileService->copyFile( $remoteFile->remote_id, $translatedFileTitle );

        \RemoteFiles_RemoteFileDao::insert( $id_file, $id_job, $copiedFile->id, $this->serviceStruct->id );

        $this->grantFileAccessByUrl( $copiedFile->id );

    }

    public function grantFileAccessByUrl( $fileId ) {
        if ( !$this->__getUser() ) {
            throw new Exception( 'Cannot procede without a User' );
        }

        $urlPermission = new \Google_Service_Drive_Permission();
        $urlPermission->setValue( $this->user->email );
        $urlPermission->setType( 'anyone' );
        $urlPermission->setRole( 'reader' );
        $urlPermission->setWithLink( true );

        return $this->getService()->permissions->insert( $fileId, $urlPermission );
    }


    public function importFile( $fileId ) {

        if ( !isset( $this->guid ) ) {
            throw new Exception( 'conversion params not set' );
        }

        try {
            $service = $this->getService();

            $file  = $service->files->get( $fileId );
            $mime  = RemoteFileService::officeMimeFromGoogle( $file->mimeType );
            $links = $file->getExportLinks();

            $downloadUrl = '';

            if ( $links != null ) {
                $downloadUrl = $links[ $mime ];
            } else {
                $downloadUrl = $file->getDownloadUrl();
            }

            if ( $downloadUrl ) {

                $fileName       = $this->sanetizeFileName( $file->getTitle() );
                $file_extension = RemoteFileService::officeExtensionFromMime( $file->mimeType );

                if ( substr( $fileName, -5 ) !== $file_extension ) {
                    $fileName .= $file_extension;
                }

                $request     = new \Google_Http_Request( $downloadUrl, 'GET', null, null );
                $httpRequest = $service
                        ->getClient()
                        ->getAuth()
                        ->authenticatedRequest( $request );

                if ( $httpRequest->getResponseHttpCode() == 200 ) {
                    $body      = $httpRequest->getResponseBody();
                    $directory = Utils::uploadDirFromSessionCookie( $this->guid );

                    if ( !is_dir( $directory ) ) {
                        mkdir( $directory, 0755, true );
                    }

                    $filePath = Utils::uploadDirFromSessionCookie( $this->guid, $fileName );
                    $saved    = file_put_contents( $filePath, $httpRequest->getResponseBody() );

                    if ( $saved !== false ) {
                        $fileHash = sha1_file( $filePath );

                        $this->addFiles( $fileId, $fileName, $fileHash );

                        $this->doConversion( $fileName );
                    } else {
                        throw new Exception( 'Error when saving file.' );
                    }
                } else {
                    throw new Exception( 'Error when downloading file.' );
                }
            } else {
                throw new Exception( 'Unable to get the file URL.' );
            }
        } catch ( Exception $e ) {
            \Log::doJsonLog( $e->getMessage() );

            return false;
        }
    }


    private function sanetizeFileName( $fileName ) {
        return str_replace( '/', '_', $fileName );
    }


    private function doConversion( $file_name ) {
        $uploadDir = $this->guid;

        $intDir = INIT::$UPLOAD_REPOSITORY .
                DIRECTORY_SEPARATOR . $uploadDir;

        $errDir = INIT::$STORAGE_DIR .
                DIRECTORY_SEPARATOR .
                'conversion_errors' .
                DIRECTORY_SEPARATOR . $uploadDir;

        $conversionHandler = new ConversionHandler();
        $conversionHandler->setFileName( $file_name );
        $conversionHandler->setSourceLang( $this->source_lang );
        $conversionHandler->setTargetLang( $this->target_lang );
        $conversionHandler->setSegmentationRule( $this->seg_rule );
        $conversionHandler->setCookieDir( $uploadDir );
        $conversionHandler->setIntDir( $intDir );
        $conversionHandler->setErrDir( $errDir );

        $this->featureSet = new \FeatureSet();
        $this->featureSet->loadFromUserEmail( $this->__getUser()->email );
        $conversionHandler->setFeatures( $this->featureSet );
        $conversionHandler->setUserIsLogged( true );

        $conversionHandler->doAction();

        return $conversionHandler->getResult();
    }


}