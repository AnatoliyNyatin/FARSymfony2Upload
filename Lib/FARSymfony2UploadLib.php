<?php

namespace faparicior\FARSymfony2UploadBundle\Lib;

use Imagine\Image\Box;
use Imagine\Image\Point;
use League\Flysystem\MountManager;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\FileBag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Translation\Translator;

class FARSymfony2UploadLib
{
    protected $options;
    protected $session;
    private $id_session;
    private $request;
    private $trans;
    private $params;
    private $local_filesystem;
    private $remote_filesystem;

    /**
     * @param Translator $translator
     * @param RequestStack $request_stack
     * @param Session $session
     * @param string $param_prefix
     * @param string $param_temp_path
     * @param string $param_temp_path_url_prefix
     * @param string $param_thumbnail_directory_prefix
     * @param string $param_thumbnail_driver
     * @param string $param_thumbnail_size
     * @param boolean $param_create_thumbnail
     * @param string $param_max_file_size
     * @param string $param_max_files_upload
     * @param string $param_file_extensions_allowed
     * @param string $local_filesystem
     * @param string $remote_filesystem
     * @param MountManager $oneup_mountmanager
     * @param mixed $options
     */
    public function __construct(
        Translator $translator,
        RequestStack $request_stack,
        Session $session,
        $param_prefix,
        $param_crop_url,
        $param_temp_path,
        $param_temp_path_url_prefix,
        $param_thumbnail_directory_prefix,
        $param_thumbnail_driver,
        $param_thumbnail_size,
        $param_create_thumbnail,
        $param_max_file_size,
        $param_max_files_upload,
        $param_file_extensions_allowed,
        $local_filesystem,
        $remote_filesystem,
        $oneup_mountmanager,
        $options = null
    ) {
        $this->session = $session;
        $this->request = $request_stack->getCurrentRequest();
        $this->options = $options;
        $this->trans = $translator;

        $this->params['param_prefix'] = $param_prefix;
        $this->params['param_crop_url'] = $param_crop_url;
        $this->params['param_temp_path'] = $param_temp_path;
        $this->params['param_temp_path_url_prefix'] = $param_temp_path_url_prefix;
        $this->params['param_thumbnail_directory_prefix'] = $param_thumbnail_directory_prefix;
        $this->params['param_thumbnail_driver'] = $param_thumbnail_driver;
        $this->params['param_thumbnail_size'] = $param_thumbnail_size;
        $this->params['param_create_thumbnail'] = $param_create_thumbnail;
        $this->params['param_max_file_size'] = $param_max_file_size;
        $this->params['param_max_files_upload'] = $param_max_files_upload;
        $this->params['param_file_extensions_allowed'] = $param_file_extensions_allowed;

        $this->local_filesystem = $oneup_mountmanager->getFileSystem($local_filesystem);
        $this->remote_filesystem = $oneup_mountmanager->getFileSystem($remote_filesystem);
    }

    /**
     * @param $id_session
     *
     * @return mixed
     */
    public function processUpload($id_session)
    {
        $this->id_session = $id_session;

        $response['data'] = ['files' => []];
        /* @var FileBag $filebag */
        foreach ($this->request->files as $filebag) {
            /* @var UploadedFile $file */
            foreach ($filebag as $index => $uploadedFile) {
                $properties = $this->getFileProperties($uploadedFile);

                $properties['uid'] = $this->discoverLocalTempFilename($properties);
                $properties['unique_name'] = $properties['uid'] . '.' . $properties['extension'];
                $properties['thumbnail_name'] = $this->getFileNameOrThumbnail($properties['unique_name'], true);

                $validFile = $this->validateFile($properties);
                if ($validFile[0] == true) {
                    $properties['dest'] = $properties['session'].'/'.$id_session.'/';
                    $pathDest = $properties['dest'] . $properties['unique_name'];

                    $contents = file_get_contents($properties['real_path']);
                    $this->local_filesystem->write($pathDest, $contents);
                    $response['data']['files'][] = $this->getjQueryUploadResponse($properties, $validFile);
                }
            }
        }

        $response['headers'] = $this->getHeadersJSON();

        return $response;
    }

    /**
     * @param UploadedFile $file
     */
    private function imageFixOrientation(UploadedFile $file)
    {
        $filePath = $file->getPathname();
        $type = $file->getClientMimeType();

        $meta = @exif_read_data($filePath);

        if (!empty($meta['Orientation'])) {
            $imageResource = false;

            switch ($type) {
                case 'image/jpeg':
                    $imageResource = imagecreatefromjpeg($filePath);
                    break;
                case 'image/png':
                    $imageResource = imagecreatefrompng($filePath);
                    break;
            }

            if ($imageResource) {
                switch ($meta['Orientation']) {
                    case 3:
                        $image = imagerotate($imageResource, 180, 0);
                        break;
                    case 6:
                        $image = imagerotate($imageResource, -90, 0);
                        break;
                    case 8:
                        $image = imagerotate($imageResource, 90, 0);
                        break;
                    default:
                        $image = $imageResource;
                }
                imagejpeg($image, $filePath, 90);

                imagedestroy($image);
            }

        }
    }

    /**
     * @param $action
     *
     * @return bool
     */
    public function evalDelete($action)
    {
        if (($this->request->getMethod() === 'POST' && $this->request->request->get('_method') == 'DELETE') ||
            ($this->request->getMethod() === 'DELETE' && $action == 'DELETE')
        ) {
            return true;
        }
        return false;
    }

    /**
     * @param $id_session
     * @param $php_session
     * @param $image
     *
     * @return array()
     */
    public function processDelete($id_session, $php_session, $image)
    {
        $path = $php_session.'/'.$id_session.'/';
        return $this->deleteFile($path, $image);
    }

    /**
     * @param $id_session
     * @param $php_session
     * @param $image
     *
     * @return array()
     */
    public function processDeleteRemote($path, $image)
    {
        $path = $this->params['param_temp_path'].'/../'.$path.'/';
        $response = $this->deleteFile($path, $image);

        return $response;
    }

    /**
     * @param $php_session
     * @param $id_session
     *
     * @return array()
     */
    public function getListFilesLocal($php_session, $id_session)
    {
        $filesNew = array();

        $files = $this->local_filesystem->listContents($php_session.'/'.$id_session);
        foreach ($files as $file) {
            if ($file['type'] == 'file') {
                array_push($filesNew, $this->mappingFileSystem($file, 'file'));
            }
        }

        if ($this->params['param_create_thumbnail']) {
            $files = $this->local_filesystem->listContents(
                $php_session.'/'.$id_session.'/'.
                $this->params['param_thumbnail_directory_prefix']
            );
            foreach ($files as $file) {
                if ($file['type'] == 'file') {
                    array_push($filesNew, $this->mappingFileSystem($file, 'thumbnail'));
                }
            }
        }

        return $filesNew;
    }

    /**
     * @param $php_session
     * @param $id_session
     *
     * @return array()
     */
    public function getListFilesRemote($path)
    {
        $filesNew = array();

        $files = $this->remote_filesystem->listContents($path);
        foreach ($files as $file) {
            if ($file['type'] == 'file') {
                array_push($filesNew, $this->mappingFileSystem($file, 'file', true));
            }
        }


        if ($this->params['param_create_thumbnail']) {
            $files = $this->remote_filesystem->listContents($path .'/'.
                $this->params['param_thumbnail_directory_prefix']);
            foreach ($files as $file) {
                if ($file['type'] == 'file') {
                    array_push($filesNew, $this->mappingFileSystem($file, 'thumbnail', true));
                }
            }
        }

        return $filesNew;
    }

    /**
     * @param array $files
     * @param string $type
     *
     * @param bool $remote
     * @return array
     */
    private function mappingFileSystem($files, $type, $remote = false)
    {
        /*
        0 = {array} [8]
         type = "file"
         path = "d0i8nvm9p9h3v9k08vn8jl1qs7/123/Captura de pantalla de 2015-12-04 10:00:44.png"
         timestamp = 1450435418
         size = 43157
         dirname = "d0i8nvm9p9h3v9k08vn8jl1qs7/123"
         basename = "Captura de pantalla de 2015-12-04 10:00:44.png"
         extension = "png"
         filename = "Captura de pantalla de 2015-12-04 10:00:44"
        */

        $filesNew['type'] = $files['type'];
        $filesNew['timestamp'] = $files['timestamp'];
        $filesNew['size'] = $files['size'];
        $filesNew['pathOrig'] = $files['path'];
        $filesNew['dirnameOrig'] = $files['dirname'];
        $filesNew['basenameOrig'] = $files['basename'];
        $filesNew['extensionOrig'] = $files['extension'];
        $filesNew['filenameOrig'] =$files['filename'];
        $filesNew['pathDest'] = $files['path'];
        $filesNew['path'] = (!$remote ? $this->getTempPathPrefix() : $this->params['param_temp_path_url_prefix'] . '/')
            . $files['path'];
        if ($type == 'file') {
            $filesNew['thumbnail'] = false;
        } else {
            $filesNew['thumbnail'] = true;
        }
        $filesNew['dirnameDest'] = $files['dirname'];
        $filesNew['basenameDest'] = $files['basename'];
        $filesNew['extensionDest'] = $files['extension'];
        $filesNew['filenameDest'] = $files['filename'];

        return $filesNew;
    }

    /**
     * @param array $files
     * @param string $path
     *
     * @return array()
     */
    public function setListFilesPathRemote($files, $path)
    {
        $filesNew = array();

        foreach ($files as $file) {
            if ($file['thumbnail'] == true) {
                $path = $path.'/'.$this->params['param_thumbnail_directory_prefix'];
            }
            $file['pathDest'] = $path.'/'.$file['basenameOrig'];
            $file['dirnameDest'] = $path;
            array_push($filesNew, $file);
        }

        return $filesNew;
    }

    /**
     * @param array $files
     * @param string $tempPath
     * @param string $path
     *
     * @return array()
     */
    public function setReplaceTempListFilesPath($files, $tempPath, $path)
    {
        $filesNew = array();

        foreach ($files as $file) {
            /*if ($file['thumbnail'] == true) {
                $path = $path.'/'.$this->params['param_thumbnail_directory_prefix'];
            }*/
            $file['pathDest'] = str_replace($tempPath, $path, $file['pathDest']);
            $file['dirnameDest'] = $path;
            array_push($filesNew, $file);
        }

        return $filesNew;
    }



    /**
     * @param array $files
     * @param boolean $rewriteFile
     *
     * @return array()
     */
    public function syncTempFilesLocal($files, $rewriteFile)
    {
        $filesNew = array();

        foreach ($files as $file) {
            $exist = $this->local_filesystem->has($file['pathDest']);
            if (($exist && $rewriteFile) || !$exist) {
                $contents = $this->local_filesystem->read($file['pathOrig']);
                if (!$exist) {
                    $file['saved'] = $this->local_filesystem->write($file['pathDest'], $contents);
                } else {
                    $file['saved'] = $this->local_filesystem->update($file['pathDest'], $contents);
                }
                $file['duplicated'] = false;
            } else {
                $file = $this->discoverLocalFilename($file);
                $contents = $this->local_filesystem->read($file['pathOrig']);
                $file['saved'] = $this->local_filesystem->write($file['pathDest'], $contents);
                $file['duplicated'] = true;
            }
            array_push($filesNew, $file);
        }

        return $filesNew;
    }

    /**
     * @param array $file
     *
     * @return array()
     */
    public function discoverLocalFilename($file)
    {
        $i = 1;

        if ($this->local_filesystem->has($file['dirnameDest'].'/'.
            $file['filenameDest'].'.'.
            $file['extensionDest'])) {
            while ($this->local_filesystem->has($file['dirnameDest'].'/'.
                $file['filenameDest'].'('.$i.')'.'.'.
                $file['extensionDest'])) {
                $i++;
            }
            $file['filenameDest'] = $file['filenameDest'].'('.$i.')';
            $file['basenameDest'] = $file['filenameDest'].'.'.
                $file['extensionDest'];
            $file['pathDest'] = $file['dirnameDest'].'/'.
                $file['basenameDest'];
        }

        return $file;
    }

    /**
     * @param array $files
     * @param boolean $rewriteFile
     *
     * @return array()
     */
    public function syncFilesLocalRemote($files, $rewriteFile)
    {
        $filesNew = [];

        foreach ($files as $file) {
            $exist = $this->remote_filesystem->has($file['pathDest']);
            if (($exist && $rewriteFile) || !$exist) {
                $contents = $this->local_filesystem->read($file['pathOrig']);
                if (!$exist) {
                    $file['saved'] = $this->remote_filesystem->write($file['pathDest'], $contents);
                } else {
                    $file['saved'] = $this->remote_filesystem->update($file['pathDest'], $contents);
                }
                $file['duplicated'] = false;
            } else {
                $file = $this->discoverRemoteFilename($file);
                $contents = $this->local_filesystem->read($file['pathOrig']);
                $file['saved'] = $this->remote_filesystem->write($file['pathDest'], $contents);
                $file['duplicated'] = true;
            }
            $filesNew[] = $file;
        }

        return $filesNew;
    }

    /**
     * @param array $file
     *
     * @return array()
     */
    public function discoverRemoteFilename($file)
    {
        $i = 1;

        if ($this->remote_filesystem->has($file['dirnameDest'].'/'.
            $file['filenameDest'].'.'.
            $file['extensionDest'])) {
            while ($this->remote_filesystem->has($file['dirnameDest'].'/'.
                $file['filenameDest'].'('.$i.')'.'.'.
                $file['extensionDest'])) {
                $i++;
            }
            $file['filenameDest'] = $file['filenameDest'].'('.$i.')';
            $file['basenameDest'] = $file['filenameDest'].'.'.
                $file['extensionDest'];
            $file['pathDest'] = $file['dirnameDest'].'/'.
                $file['basenameDest'];
        }

        return $file;
    }

    /**
     * @param $properties
     *
     * @return string
     */
    public function discoverLocalTempFilename($properties)
    {
        $i = 1;
        $filesystem = new Filesystem();

        //$fileProperties = pathinfo($properties['name_uid']);
        $filename = $properties['uid'];
        $extension = $properties['extension'];
        $dirname = $properties['temp_dir'];

        if ($filesystem->exists($dirname.'/'.
            $filename.'.'.
            $extension)) {
            while ($filesystem->exists($dirname.'/'.
                $filename.'('.$i.')'.'.'.
                $extension)) {
                $i++;
            }

            $filenameDef = $filename.'('.$i.')'/*.'.'.$extension*/;
        } else {
            $filenameDef = $filename/* . $extension*/;
        }

        return $filenameDef;
    }

    /**
     * @param array $files
     *
     * @return array()
     */
    public function deleteFilesLocal($files)
    {
        $file = array_shift($files);
        $this->local_filesystem->deleteDir($file['dirnameOrig']);
        array_unshift($files, $file);

        return $files;
    }

    public function deleteFilesRemote($files)
    {
        foreach ($files as $file) {
            $this->remote_filesystem->delete($file['pathOrig']);
        }
    }

    /**
     * @param UploadedFile $file
     *
     * @return array()
     */
    private function getFileProperties($file)
    {
        $properties = array();
        $extension = $file->guessExtension();
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;
        $properties['uid'] = md5($file->getClientOriginalName().uniqid('image', true).time());

        $properties['unique_name'] = $properties['uid'] . '.' . $extension;
        //$properties['original_name'] = md5($file->getClientOriginalName().uniqid('image', true).time()).'.'.$extension;
        $properties['upload_original_name'] = $file->getClientOriginalName();
        $properties['extension'] = $extension;
        $properties['real_path'] = $file->getRealPath();

        //$properties['uid'] = $this->getFileNameOrThumbnail($properties['unique_name'], false);
        //$properties['name_uid'] = $properties['original_name'];
        $properties['thumbnail_name'] = $this->getFileNameOrThumbnail($properties['unique_name'], true);
        $properties['size'] = $file->getClientSize();
        $properties['maxfilesize'] = $file->getMaxFilesize();
        $properties['mimetype'] = $file->getMimeType();
        $properties['session'] = $this->session->getId();
        $properties['id_session'] = $this->id_session;
        $properties['temp_dir'] = $this->session->getId().'/'.$properties['id_session'];

        return $properties;
    }

    /**
     * @param array $properties
     *
     * @return array()
     */
    private function validateFile($properties)
    {
        $result = array(true, 'Always fine');

        if (!$this->validateFileSize($properties)) {
            $result = array(false, $this->trans->trans(
                'File.size.exceed.maximum.allowed'
            ));
        } else {
            if (!$this->validateFileExtension($properties)) {
                $result = array(false, $this->trans->trans(
                    'File.type.not.allowed'
                ));
            }
        }
        if (!$this->validateUploadMaxFiles($properties)) {
            $result = array(false, $this->trans->trans(
                'Too.much.files.for.upload',
                array('%max_files_upload%' => $this->params['param_max_files_upload'])
            ));
        }

        return $result;
    }

    /**
     * @param array $properties
     *
     * @return bool
     */
    private function validateFileSize($properties)
    {
        if ($properties['size'] > $this->params['param_max_file_size']) {
            return false;
        }
        return true;
    }

    /**
     * @param array $properties
     *
     * @return bool
     */
    private function validateFileExtension($properties)
    {

        return in_array($properties['extension'], $this->params['param_file_extensions_allowed'], true);
    }

    /**
     * @param array $properties
     *
     * @return bool
     */
    private function validateUploadMaxFiles($properties)
    {
        $valid = false;
        $directoryFindFiles = $this->params['param_temp_path'] . '/' .
            $properties['session'] . '/' .
            $properties['id_session'];

        $fs = new Filesystem();
        if ($fs->exists($directoryFindFiles)) {
            $finder = new Finder();
            $countFiles = $finder->files()
                ->in($directoryFindFiles)
                ->count();

            /* max_files_upload * 2 because the thumbnails */
            if ($countFiles < $this->params['param_max_files_upload']) {
                $valid = true;
            }
        } else {
            $valid = true;
        }
        return $valid;
    }

    /**
     * @return array $header
     */
    private function getHeadersJSON()
    {
        $server_accept = $this->request->server->get('HTTP_ACCEPT');

        if ($server_accept && strpos($server_accept, 'application/json') !== false) {
            $type_header = 'application/json';
        } else {
            $type_header = 'text/plain';
        }
        return array(
            'Vary' => 'Accept',
            'Content-type' => $type_header,
            'Pragma' => 'no-cache',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Content-Disposition' => 'inline; filename="files.json"',
            'X-Content-Type-Options' => 'nosniff',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'OPTIONS, HEAD, GET, POST, PUT, DELETE',
            'Access-Control-Allow-Headers' => 'X-File-Name, X-File-Type, X-File-Size',
        );
    }

    /**
     * @param $path
     * @param $file
     *
     * @return string
     */
    private function deleteFile($path, $file)
    {
        // TODO: Borrar miniaturas PS
        $fileTemp = $path.$file;
        $thumbnail = $path.$this->getFileNameOrThumbnail($file, true);

        if ($this->local_filesystem->has($fileTemp)) {
            $this->local_filesystem->delete($fileTemp);
        }
        if ($this->local_filesystem->has($thumbnail)) {
            $this->local_filesystem->delete($thumbnail);
        }
        $response[0][$fileTemp] = true;

        return $response;
    }

    /**
     * @param string $filename
     * @param string $thumbnail
     *
     * @return string
     */
    private function getFileNameOrThumbnail($filename, $thumbnail)
    {
        $original_name = pathinfo($filename);
        $name = $original_name['filename'];
        $extension = $original_name['extension'];

        if ($thumbnail) {
            return $this->params['param_thumbnail_directory_prefix'].'/'.
                $name.'_'.
                $this->params['param_thumbnail_size'].'.'.$extension;
        } else {
            return $name;
        }
    }

    /**
     * @param array $properties
     * @param array $validFile
     *
     * @return array()
     */
    private function getJQueryUploadResponse($properties, $validFile)
    {
        $response['name'] = $properties['unique_name'];
        $response['size'] = $properties['size'];
        if ($validFile[0]) {
            $response['url'] = $this->getURLResponse($properties);
            $response['thumbnailUrl'] = $this->getThumbnailURLResponse($properties);
            $response['deleteUrl'] =  $this->getURLResponseDelete($properties);
            $response['deleteType'] = 'DELETE';
            $response['type'] = $properties['mimetype'];
        } else {
            $response['error'] = $validFile[1];
        }

        return $response;
    }

    /**
     * @param $properties
     *
     * @return string
     */
    private function getURLResponse($properties)
    {
        return $this->params['param_temp_path_url_prefix'].'/tmp/'.
            $properties['session'].'/'.
            $properties['id_session'].'/'.
            $properties['unique_name'];
    }

    /**
     * @param $properties
     *
     * @return string
     */
    private function getURLResponseDelete($properties)
    {
        return $this->request->getBaseUrl().'/'.
            $this->params['param_prefix'].
            '/tmp/'.
            $properties['session'].'/'.
            $properties['id_session'].'/'.
            $properties['unique_name'].'/'.
            'DELETE';
    }

    /**
     * @param $properties
     *
     * @return string
     */
    private function getThumbnailURLResponse($properties)
    {
        return $this->params['param_temp_path_url_prefix'].'/tmp/'.
            $properties['session'].'/'.
            $properties['id_session'].'/'.
            $properties['thumbnail_name'];
    }

    /**
     * @param $filename
     * @return array
     */
    public function getThumbnailFileName($filename)
    {
        $original_name = pathinfo($filename);
        $name = $original_name['filename'];
        $extension = $original_name['extension'];

        return [
            'filename' => $name.'_'.$this->params['param_thumbnail_size'].'.'.$extension,
            'prefix_path' => $this->params['param_temp_path_url_prefix'].'/tmp/'
        ];
    }

    /**
     * @return string
     */
    public function getTempPathPrefix()
    {
        return $this->params['param_temp_path_url_prefix'].'/tmp/';
    }

    public function getThumbnailTempFilePath($filename, $session, $id_session)
    {
        $properties = $this->getThumbnailFileName($filename);
        $properties['thumbnail_name'] = $properties['filename'];
        $properties['session'] = $session;
        $properties['id_session'] = $id_session;

        return $this->getThumbnailURLResponse($properties);
    }

    //Very bad function
    public function createCropped($file)
    {
        //TODO: Rewrite this function. Need different crop without S3
        $params = [
            'key' => '/' . $this->remote_filesystem->getAdapter()->getPathPrefix() . $file['path'],
            'x' => $file['x'],
            'y' => $file['y'],
            'scale' => $file['scale'],
            'angle' => $file['angle'],
            'width' => $file['width'],
            'height' => $file['height'],
        ];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->params['param_crop_url'].'?'.http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $data = json_decode(curl_exec($ch));
        curl_close($ch);

        return $data->name ?? null;
    }

    /**
     * @param $properties
     */
    private function createThumbnail($properties)
    {
        // TODO: Generar miniaturas PS
        $thumbnail_size = explode('x', $this->params['param_thumbnail_size']);
        $imagine = $this->getImagineEngine();

        $size = new \Imagine\Image\Box($thumbnail_size[0], $thumbnail_size[1]);
        $mode = \Imagine\Image\ImageInterface::THUMBNAIL_INSET;

        $fs = new Filesystem();
        $fs->mkdir($properties['temp_dir'].'/'.
            $this->params['param_thumbnail_directory_prefix']);

        $imagine->open($properties['temp_dir'].'/'.
            $properties['unique_name'])
            ->thumbnail($size, $mode)
            ->save($properties['temp_dir'].'/'.
                $properties['thumbnail_name']);
    }

    /**
     * @return \Imagine\Gd\Imagine|\Imagine\Gmagick\Imagine|\Imagine\Imagick\Imagine
     */
    private function getImagineEngine()
    {
        switch ($this->params['param_thumbnail_driver']) {
            case 'gd':
                $imagine = new \Imagine\Gd\Imagine();
                break;
            case 'gmagik':
                $imagine = new \Imagine\Gmagick\Imagine();
                break;
            default:
                $imagine = new \Imagine\Imagick\Imagine();
        }

        return $imagine;
    }

}
