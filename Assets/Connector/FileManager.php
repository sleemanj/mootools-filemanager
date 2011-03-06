<?php
/*
Script: FileManager.php
  MooTools FileManager - Backend for the FileManager Script

Authors:
 - Christoph Pojer (http://cpojer.net) (author)
 - James Ehly (http://www.devtrench.com)
 - Fabian Vogelsteller (http://frozeman.de)

License:
  MIT-style license.

Copyright:
  Copyright (c) 2009 [Christoph Pojer](http://cpojer.net)

Dependencies:
  - Upload.php
  - Image.class.php
  - getId3 Library

Options:
  - directory: (string) The base directory to be used for the FileManager
  - assetBasePath: (string, optional) The path to all images and swf files used by the filemanager
  - thumbnailPath: (string) The path where the thumbnails of the pictures will be saved
  - mimeTypesPath: (string, optional) The path to the MimeTypes.ini file.
  - dateFormat: (string, defaults to *j M Y - H:i*) The format in which dates should be displayed
  - maxUploadSize: (integer, defaults to *20280000* bytes) The maximum file size for upload in bytes
  - maxImageSize: (integer, default is 1024) The maximum number of pixels an image can have, if the user enables "resize on upload"
  - upload: (boolean, defaults to *true*) allow uploads, this is also set in the FileManager.js (this here is only for security protection when uploads should be deactivated)
  - destroy: (boolean, defaults to *true*) allow files to get deleted, this is also set in the FileManager.js (this here is only for security protection when file/directory delete operations should be deactivated)
  - safe: (string, defaults to *true*) If true, disallows 'exe', 'dll', 'php', 'php3', 'php4', 'php5', 'phps' and saves them as 'txt' instead.
  - chmod: (integer, default is 0777) the permissions set to the uploaded files and created thumbnails (must have a leading "0", e.g. 0777)

Notes on relative paths and safety / security:

  If any option is specifying a relative path, e.g. '../Assets' or 'Media/Stuff/', this is assumed to be relative to the request URI path,
  i.e. dirname($_SERVER['SCRIPT_NAME']).

  Requests may post/submit relative paths as arguments to their FileManager events/actions in $_GET/$_POST, and those relative paths will be
  regarded as relative to the request URI handling script path, i.e. dirname($_SERVER['SCRIPT_NAME']) to make the most
  sense from bother server and client coding perspective.


  We also assume that any of the paths may be specified from the outside, so each path is processed and filtered to prevent malicious intent
  from succeeding. (An example of such would be an attacker posting his own 'destroy' event request requesting the destruction of
  '../../../../../../../../../etc/passwd' for example. In more complex rigs, the attack may be assisted through attacks at these options' paths,
  so these are subjected to the same scrutiny in here.)

  All paths, absolute or relative, as passed to the event handlers (see the onXXX methods of this class) are ENFORCED TO ABIDE THE RULE 
  'every path resides within the BASEDIR rooted tree' without exception.
  When paths apparently don't, they are forcibly coerced into adherence to this rule. Because we can do without exceptions to important rules. ;-)
  
  BASEDIR equals the path pointed at by the options['directory'] setting. It is therefore imperative that you ensure this value is 
  correctly set up; worst case, this setting will equal DocumentRoot.
  In other words: you'll never be able to reach any file or directory outside this site's DocumentRoot directory tree, ever.


  When you need your paths to be restricted to the bounds of the options['directory'] tree (which is a subtree of the DocumentRoot based
  tree), you may wish to use the CheckFile(), getPath() and getDir() methods instead of getRealPath() and getRealDir(), as the latter 
  restrict targets to within the DocumentRoot tree only.

  getPath() and getRealPath() both deliver absolute paths relative to DocumentRoot, hence suitable for use in URIs and feeding to client side
  scripts, while getRealDir() and getDir() both return absolute paths in the server filesystem perspective, i.e. the latter are suitable for
  server side script based file operation functions.
*/

if (version_compare(PHP_VERSION, '5.2.0') < 0)
{
    die('FileManager requires PHP 5.2.0 or later');
}

if (!defined('MTFM_PATH'))
{
    $base = str_replace('\\','/',dirname(__FILE__));
    define('MTFM_PATH', $base);
}

require_once(MTFM_PATH . '/Upload.php');
require_once(MTFM_PATH . '/Image.class.php');

class FileManager {

  protected $path = null;
  protected $length = null;
  protected $basedir = null;                    // absolute path equivalent, filesystem-wise, for options['directory']
  protected $basename = null;                   // currently unused
  protected $options;
  protected $post;
  protected $get;

  public function __construct($options)
  {
    $this->options = array_merge(array(
      /*
       * Note that all default paths as listed below are transformed to DocumentRoot-based paths
       * through the getRealPath() invocations further below:
       */
      'directory' => MTFM_PATH . '/Files/',
      'assetBasePath' => MTFM_PATH . '/../../Assets/',
      'thumbnailPath' => MTFM_PATH . '/../../Assets/Thumbs/',  // written like this so we're completely clear on where the default thumbnails directory will be
      'mimeTypesPath' => MTFM_PATH . '/MimeTypes.ini',
      'dateFormat' => 'j M Y - H:i',
      'maxUploadSize' => 2600 * 2600 * 3,
      'maxImageSize' => 1024,
      'upload' => true,
      'destroy' => true,
      'create' => true,
      'move' => true,
      'download' => true,
      /* ^^^ this last one is easily circumnavigated if it's about images: when you can view 'em, you can 'download' them anyway.
       *     However, for other mime types which are not previewable / viewable 'in their full bluntal nugity' ;-) , this will
       *     be a strong deterent.
       *
       *     Think Springer Verlag and PDFs, for instance. You can have 'em, but only /after/ you've ...
       */
      'safe' => true,
      'chmod' => 0777
    ), (is_array($options) ? $options : array()));

    $this->options['thumbnailPath'] = FileManagerUtility::getRealPath($this->options['thumbnailPath'], $this->options['chmod'], true); // create path if nonexistent
    $this->options['assetBasePath'] = FileManagerUtility::getRealPath($this->options['assetBasePath']);
    $this->options['mimeTypesPath'] = FileManagerUtility::getRealDir($this->options['mimeTypesPath'], 0, false, false); // filespec, not a dirspec!
    $this->options['directory'] = FileManagerUtility::getRealPath($this->options['directory']);
    $this->basedir = FileManagerUtility::getSiteRoot() . $this->options['directory'];
    $this->basename = pathinfo($this->basedir, PATHINFO_BASENAME) . '/';
    $this->length = strlen($this->basedir);
    //$this->listType = (isset($_POST['type']) && $_POST['type'] == 'list') ? 'list' : 'thumb';          // wrong place for this; do it in the event handler methods!
    //$this->filter = (isset($_POST['filter']) && !empty($_POST['filter'])) ? $_POST['filter'].'/' : '';  // wrong place for this; do it in the event handler methods!

    header('Expires: Fri, 01 Jan 1990 00:00:00 GMT');
    header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');

    $this->get = $_GET;
    $this->post = $_POST;
  }

  public function fireEvent($event){
    $event = $event ? 'on' . ucfirst($event) : null;
    if (!$event || !method_exists($this, $event)) $event = 'onView';

    $this->{$event}();
  }

  /**
   * @return array the FileManager options and settings.
   */
  public function getSettings(){
    return array_merge(array(
        'basedir' => $this->basedir,
        'basename' => $this->basename
    ), $this->options);
  }

  private function _onView($dir, $json, $mime_filter, $list_type)
  {
    $files = ($files = glob($dir . '*')) ? $files : array();

	$root = FileManagerUtility::getSiteRoot();
	
    if ($dir != $this->basedir) array_unshift($files, $dir . '..');
    natcasesort($files);
    foreach ($files as $file)
    {
      $file = self::normalize($file);
      $url = str_replace($root,'',$file);

      $mime = $this->getMimeType($file);
      if ($mime_filter && $mime != 'text/directory' && !FileManagerUtility::startsWith($mime, $mime_filter))
        continue;

      /*
       * each image we inspect may throw an exception due to a out of memory warning
       * (which is far better than without those: a silent fatal abort!)
       *
       * However, now that we do have a way to check most memory failures occurring in here (due to large images
       * and too little available RAM) we /still/ want a directory view; we just want to skip/ignore/mark those
       * overly large ones.
       */
      $thumb = false;
      try
      {
        // access the image and create a thumbnail image; this can fail dramatically
        if(strpos($mime,'image') !== false)
          $thumb = $this->getThumb($file);
      }
      catch (Exception $e)
      {
         // do nothing, except mark image as 'not suitable for thumbnailing'
      }

      $icon = ($list_type == 'thumb' && $thumb)
        ? $this->options['thumbnailPath'] . $thumb
        : $this->getIcon($file, $list_type != 'thumb'); // TODO: add extra icons for those bad format and superlarge images with make us b0rk?

      // list files, except the thumbnail folder
      if($url != substr($this->options['thumbnailPath'],0,-1)) {
        $out[is_dir($file) ? 0 : 1][] = array(
          'path' => FileManagerUtility::rawurlencode_path($url),
          'name' => pathinfo($file, PATHINFO_BASENAME),
          'date' => date($this->options['dateFormat'], filemtime($file)),
          'mime' => $mime,
          'thumbnail' => FileManagerUtility::rawurlencode_path($icon),
          'icon' => FileManagerUtility::rawurlencode_path($this->getIcon($file,true)),
          'size' => filesize($file)
        );
      }
    }
    return array_merge((is_array($json) ? $json : array()), array(
        //'assetBasePath' => $this->options['assetBasePath'],
        //'thumbnailPath' => $this->options['thumbnailPath'],
        //'ia_directory' => $this->options['directory'],
        //'ia_dir' => $dir,
        'root' => substr($this->options['directory'], 1),
        'path' => str_replace($root,'',$dir),
        'dir' => array(
            'name' => pathinfo($dir, PATHINFO_BASENAME),
            'date' => date($this->options['dateFormat'], filemtime($dir)),
            'mime' => 'text/directory',
            'thumbnail' => $this->getIcon($dir),
            'icon' => $this->getIcon($dir,true)
          ),
      'files' => array_merge(!empty($out[0]) ? $out[0] : array(), !empty($out[1]) ? $out[1] : array())
    ));
  }

  /**
   * Process the 'view' event (default event fired by fireEvent() method)
   *
   * Returns a JSON encoded directory view list.
   *
   * Expected parameters:
   *
   * $_POST['directory']     path relative to basedir a.k.a. options['directory'] root
   *
   * $_POST['filter']        optional mimetype filter string, amy be the part up to and
   *                         including the slash '/' or the full mimetype. Only files
   *                         matching this (set of) mimetypes will be listed.
   *                         Examples: 'image/' or 'application/zip'
   *
   * $_POST['type']          'thumb' will produce a list view including thumbnail and other
   *                         information with each listed file; other values will produce
   *                         a basic list view (similar to Windows Explorer 'list' view).
   *
   * Errors will produce a JSON encoded error report, including at least two fields:
   *
   * status                  0 for error; nonzero for success
   *
   * error                   error message
   *
   * Next to these, the JSON encoded output will, with high probability, include a
   * list view of the 'basedir' as a fast and easy fallback mechanism for client side
   * viewing code. However, severe and repetitive errors may not produce this
   * 'fallback view list' so proper client code should check the 'status' field in the
   * JSON output.
   */
  protected function onView()
  {
    try
    {
        $mime_filter = ((isset($_POST['filter']) && !empty($_POST['filter'])) ? $_POST['filter'].'/' : null);
        $list_type = ((isset($_POST['type']) && $_POST['type'] == 'list') ? 'list' : 'thumb');

        $dir = $this->getDir(!empty($this->post['directory']) ? $this->post['directory'] : null);

        $jsok = array(
                'status' => 1
            );
        $rv = $this->_onView($dir, $jsok, $mime_filter, $list_type);
        echo json_encode($rv);
    }
    catch(FileManagerException $e)
    {
        $emsg = explode(':', $e->getMessage(), 2);
        $jserr = array(
                'status' => 0,
                'error' => '${upload.' . $emsg[0] . '}' . (isset($emsg[1]) ? $emsg[1] : '')
            );
        // and fall back to showing the root directory
        try
        {
            $dir = $this->getDir();
            $rv = $this->_onView($dir, $jserr, $mime_filter, $list_type);
            echo json_encode($rv);
        }
        catch (Exception $e)
        {
            // when we fail here, it's pretty darn bad and nothing to it.
            // just push the error JSON as go.
            echo json_encode($jserr);
        }
    }
    catch(Exception $e)
    {
        // catching other severe failures; since this can be anything and should only happen in the direst of circumstances, we don't bother translating
        $jserr = array(
                'status' => 0,
                'error' => $e->getMessage()
            );
        // and fall back to showing the root directory
        try
        {
            $dir = $this->getDir();
            $rv = $this->_onView($dir, $jserr, $mime_filter, $list_type);
            echo json_encode($rv);
        }
        catch (Exception $e)
        {
            // when we fail here, it's pretty darn bad and nothing to it.
            // just push the error JSON as go.
            echo json_encode($jserr);
        }
    }
  }

  /**
   * Process the 'detail' event
   *
   * Returns a JSON encoded HTML chunk describing the specified file (metadata such
   * as size, format and possibly a thumbnail image as well)
   *
   * Expected parameters:
   *
   * $_POST['directory']     path relative to basedir a.k.a. options['directory'] root
   *
   * $_POST['file']          filename (including extension, of course) of the file to
   *                         be detailed.
   *
   * Errors will produce a JSON encoded error report, including at least two fields:
   *
   * status                  0 for error; nonzero for success
   *
   * error                   error message
   */
  protected function onDetail()
  {
  try
  {
    if (empty($this->post['directory']) || empty($this->post['file']))
        throw new FileManagerException('nofile');

    $url = $this->getPath($this->post['directory']);
    $dir = FileManagerUtility::getSiteRoot() . $url;
	$file = pathinfo($this->post['file'], PATHINFO_BASENAME);

	$dir .= $file;
	$url .= $file;
	
    if (!$this->checkFile($dir))
        throw new FileManagerException('nofile');

    // spare the '/' dir separators from URL encoding:
    $encoded_url = FileManagerUtility::rawurlencode_path($url);

    $mime = $this->getMimeType($dir);
    $content = null;

    // image
    if (FileManagerUtility::startsWith($mime, 'image/'))
    {
      // generates a random number to put on the end of the image, to prevent caching
      $randomImage = '?'.md5(uniqid(rand(),1));
      $size = @getimagesize($dir);
      // check for badly formatted image files (corruption); we'll handle the overly large ones next
      if (!$size)
        throw new FileManagerException('corrupt_img:' . $url);
      $thumbfile = $this->options['thumbnailPath'] . $this->getThumb($dir);
      $content = '<dl>
          <dt>${width}</dt><dd>' . $size[0] . 'px</dd>
          <dt>${height}</dt><dd>' . $size[1] . 'px</dd>
          <dt>mem usage:</dt><dd>' . number_format(memory_get_usage() / 1E6, 2) . ' MB : ' . number_format(memory_get_peak_usage() / 1E6, 2) . ' MB</dd>
        </dl>
        <h2>${preview}</h2>
        ';
      try
      {
          $tnc = '<a href="'.$encoded_url.'" data-milkbox="preview" title="'.htmlentities($file, ENT_QUOTES, 'UTF-8').'"><img src="' . FileManagerUtility::rawurlencode_path($thumbfile) . $randomImage . '" class="preview" alt="preview" /></a>';
      }
      catch (Exception $e)
      {
          $tnc = '<a href="'.$encoded_url.'" data-milkbox="preview" title="'.htmlentities($file, ENT_QUOTES, 'UTF-8').'"><img src="' . FileManagerUtility::rawurlencode_path($this->getIcon($file)).$randomImage . '" class="preview" alt="preview" /></a>';
      }
      $content .= $tnc;
    // text preview
    }
    elseif (FileManagerUtility::startsWith($mime, 'text/') || $mime == 'application/x-javascript')
    {
      $filecontent = file_get_contents($dir, false, null, 0);
      if (!FileManagerUtility::isBinary($filecontent))
      {
        $content = '<div class="textpreview"><pre>' . str_replace(array('$', "\t"), array('&#36;', '&nbsp;&nbsp;'), htmlentities($filecontent,ENT_QUOTES,'UTF-8')) . '</pre></div>';
      }
      else
      {
	    $fsize = filesize($dir);
        $content = '<div class="textpreview">
            <p>${size} ' . FileManagerUtility::fmt_bytecount($fsize) . ' (' . $fsize . ' Bytes)</p>
            </div>';
      }
    // zip
    }
    elseif ($mime == 'application/zip')
    {
      require_once(MTFM_PATH . '/Assets/getid3/getid3.php');

      $out = array(array(), array());
      $getid3 = new getID3();
      $getid3->Analyze($dir);
      foreach ($getid3->info['zip']['files'] as $name => $size)
      {
        $isdir = is_array($size) ? true : true;
        $out[($isdir) ? 0 : 1][$name] = '<li><a><img src="'.FileManagerUtility::rawurlencode_path($this->getIcon($name,true)).'" alt="" /> ' . $name . '</a></li>';
      }
      natcasesort($out[0]);
      natcasesort($out[1]);
      $content = '<ul>' . implode(array_merge($out[0], $out[1])) . '</ul>';
    // swf
    }
    elseif ($mime == 'application/x-shockwave-flash')
    {
      require_once(MTFM_PATH . '/Assets/getid3/getid3.php');
      $getid3 = new getID3();
      $getid3->Analyze($dir);

      $content = '<dl>
          <dt>${width}</dt><dd>' . $getid3->info['swf']['header']['frame_width']/10 . 'px</dd>
          <dt>${height}</dt><dd>' . $getid3->info['swf']['header']['frame_height']/10 . 'px</dd>
          <dt>${length}</dt><dd>' . round(($getid3->info['swf']['header']['length']/$getid3->info['swf']['header']['frame_count'])) . 's</dd>
        </dl>
        <h2>${preview}</h2>
        <div class="object">
          <object type="application/x-shockwave-flash" data="'.FileManagerUtility::rawurlencode_path($url).'" width="500" height="400">
            <param name="scale" value="noscale" />
            <param name="movie" value="'.FileManagerUtility::rawurlencode_path($url).'" />
          </object>
        </div>';
    // audio
    }
    elseif (FileManagerUtility::startsWith($mime, 'audio/'))
    {
      require_once(MTFM_PATH . '/Assets/getid3/getid3.php');
      $getid3 = new getID3();
      $getid3->Analyze($dir);
      getid3_lib::CopyTagsToComments($getid3->info);

      $dewplayer = FileManagerUtility::rawurlencode_path($this->options['assetBasePath'] . 'dewplayer.swf');
      $content = '<dl>
          <dt>${title}</dt><dd>' . $getid3->info['comments']['title'][0] . '</dd>
          <dt>${artist}</dt><dd>' . $getid3->info['comments']['artist'][0] . '</dd>
          <dt>${album}</dt><dd>' . $getid3->info['comments']['album'][0] . '</dd>
          <dt>${length}</dt><dd>' . $getid3->info['playtime_string'] . '</dd>
          <dt>${bitrate}</dt><dd>' . round($getid3->info['bitrate']/1000) . 'kbps</dd>
        </dl>
        <h2>${preview}</h2>
        <div class="object">
          <object type="application/x-shockwave-flash" data="' . $dewplayer . '" width="200" height="20" id="dewplayer" name="dewplayer">
            <param name="wmode" value="transparent" />
            <param name="movie" value="' . $dewplayer . '" />
            <param name="flashvars" value="mp3=' . FileManagerUtility::rawurlencode_path($url) . '&amp;volume=50&amp;showtime=1" />
          </object>
        </div>';
    }
    else
    {
	    $fsize = @filesize($dir);
        $content = '<div class="textpreview">
            <p>${size} ' . FileManagerUtility::fmt_bytecount($fsize) . ' (' . $fsize . ' Bytes)</p>
            </div>';
    }

    echo json_encode(array(
      'status' => 1,
      'content' => $content ? $content : '<div class="margin">
        ${nopreview}
      </div>'//<br/><button value="' . $url . '">${download}</button>
    ));
    }
    catch(FileManagerException $e)
    {
        $emsg = explode(':', $e->getMessage(), 2);
        echo json_encode(array(
                'status' => 0,
                'content' => '<div class="margin">
                  ${nopreview}
                  <div class="failure_notice">
                    <h3>${error}</h3>
                    <p>mem usage: ' . number_format(memory_get_usage() / 1E6, 2) . ' MB : ' . number_format(memory_get_peak_usage() / 1E6, 2) . ' MB</p>
                    <p>${upload.' . $emsg[0] . '}' . (isset($emsg[1]) ? $emsg[1] : '') . '</p>
                  </div>
                </div>'       // <br/><button value="' . $url . '">${download}</button>
            ));
    }
    catch(Exception $e)
    {
        // catching other severe failures; since this can be anything and should only happen in the direst of circumstances, we don't bother translating
        echo json_encode(array(
                'status' => 0,
                'content' => '<div class="margin">
                  ${nopreview}
                  <div class="failure_notice">
                    <h3>${error}</h3>
                    <p>mem usage: ' . number_format(memory_get_usage() / 1E6, 2) . ' MB : ' . number_format(memory_get_peak_usage() / 1E6, 2) . ' MB</p>
                    <p>' . $e->getMessage() . '</p>
                  </div>
                </div>'       // <br/><button value="' . $url . '">${download}</button>
            ));
    }
  }

  /**
   * Process the 'destroy' event
   *
   * Delete the specified file or directory and return a JSON encoded status of success
   * or failure.
   *
   * Note that when images are deleted, so are their thumbnails.
   *
   * Expected parameters:
   *
   * $_POST['directory']     path relative to basedir a.k.a. options['directory'] root
   *
   * $_POST['file']          filename (including extension, of course) of the file to
   *                         be detailed.
   *
   * Errors will produce a JSON encoded error report, including at least two fields:
   *
   * status                  0 for error; nonzero for success
   *
   * error                   error message
   */
  protected function onDestroy()
  {
    try
    {
        if (!$this->options['destroy'])
            throw new FileManagerException('disabled');
        if (empty($this->post['file']))
            throw new FileManagerException('nofile');

        $dir = $this->getDir(!empty($this->post['directory']) ? $this->post['directory'] : null);
		$file = pathinfo($this->post['file'], PATHINFO_BASENAME);

        $name = pathinfo($file, PATHINFO_FILENAME);
        $fileinfo = array(
			'dir' => $dir,
            'file' => $file,
            'name' => $name
        );

        if (!$this->checkFile($dir . $file))
            throw new FileManagerException('nofile');

        if (!empty($this->options['DestroyIsAuthenticated_cb']) && function_exists($this->options['DestroyIsAuthenticated_cb']) && !$this->options['DestroyIsAuthenticated_cb']($this, 'destroy', $fileinfo))
            throw new FileManagerException('authenticated');

        if (!$this->unlink($dir . $file))
            throw new FileManagerException('unlink_failed');

        echo json_encode(array(
          'status' => 1,
          'content' => 'destroyed'
        ));
    }
    catch(FileManagerException $e)
    {
        $emsg = explode(':', $e->getMessage(), 2);
        echo json_encode(array(
                'status' => 0,
                'error' => '${upload.' . $emsg[0] . '}' . (isset($emsg[1]) ? $emsg[1] : '')
            ));
    }
    catch(Exception $e)
    {
        // catching other severe failures; since this can be anything and should only happen in the direst of circumstances, we don't bother translating
        echo json_encode(array(
                'status' => 0,
                'error' => $e->getMessage()
            ));
    }
  }

  /**
   * Process the 'create' event
   *
   * Create the specified subdirectory and give it the configured permissions
   * (options['chmod'], default 0777) and return a JSON encoded status of success
   * or failure.
   *
   * Expected parameters:
   *
   * $_POST['directory']     path relative to basedir a.k.a. options['directory'] root
   *
   * $_POST['file']          name of the subdirectory to be created
   *
   * Extra input parameters considered while producing the JSON encoded directory view.
   * This may not seem relevant for an empty directory, but these parameters are also
   * considered when providing the fallback directory view in case an error occurred
   * and then the listed directory (either the parent or the basedir itself) may very
   * likely not be empty!
   *
   * $_POST['filter']        optional mimetype filter string, amy be the part up to and
   *                         including the slash '/' or the full mimetype. Only files
   *                         matching this (set of) mimetypes will be listed.
   *                         Examples: 'image/' or 'application/zip'
   *
   * $_POST['type']          'thumb' will produce a list view including thumbnail and other
   *                         information with each listed file; other values will produce
   *                         a basic list view (similar to Windows Explorer 'list' view).
   *
   * Errors will produce a JSON encoded error report, including at least two fields:
   *
   * status                  0 for error; nonzero for success
   *
   * error                   error message
   */
  protected function onCreate()
  {
    try
    {
        $mime_filter = ((isset($_POST['filter']) && !empty($_POST['filter'])) ? $_POST['filter'].'/' : null);
        $list_type = ((isset($_POST['type']) && $_POST['type'] == 'list') ? 'list' : 'thumb');

        if (!$this->options['create'])
            throw new FileManagerException('disabled');
        if (empty($this->post['directory']) || empty($this->post['file']))
            throw new FileManagerException('nofile');

        $dir = $this->getDir(!empty($this->post['directory']) ? $this->post['directory'] : null);
		$file = pathinfo($this->post['file'], PATHINFO_BASENAME);

        $name = pathinfo($file, PATHINFO_FILENAME);
        $fileinfo = array(
            'dir' => $dir,
			'subdir' => $file,
            'name' => $name,
            'chmod' => $this->options['chmod']
        );
        if (!empty($this->options['CreateIsAuthenticated_cb']) && function_exists($this->options['CreateIsAuthenticated_cb']) && !$this->options['CreateIsAuthenticated_cb']($this, 'create', $fileinfo))
            throw new FileManagerException('authenticated');

        if (!@mkdir($dir . $file, $fileinfo['chmod']))
            throw new FileManagerException('mkdir_failed');

        // success, now show the new directory as a list view:
        $jsok = array(
                'status' => 1
            );
        $rv = $this->_onView($dir . $file . '/', $jsok, $mime_filter, $list_type);
        echo json_encode($rv);
    }
    catch(FileManagerException $e)
    {
        $emsg = explode(':', $e->getMessage(), 2);
        $jserr = array(
                'status' => 0,
                'error' => '${upload.' . $emsg[0] . '}' . (isset($emsg[1]) ? $emsg[1] : '')
            );
        // and fall back to showing the PARENT directory
        try
        {
            $rv = $this->_onView($dir, $jserr, $mime_filter, $list_type);
            echo json_encode($rv);
        }
        catch (Exception $e)
        {
            // and fall back to showing the BASEDIR directory
            try
            {
                $dir = $this->getDir();
                $rv = $this->_onView($dir, $jserr, $mime_filter, $list_type);
                echo json_encode($rv);
            }
            catch (Exception $e)
            {
                // when we fail here, it's pretty darn bad and nothing to it.
                // just push the error JSON as go.
                echo json_encode($jserr);
            }
        }
    }
    catch(Exception $e)
    {
        // catching other severe failures; since this can be anything and should only happen in the direst of circumstances, we don't bother translating
        $jserr = array(
                'status' => 0,
                'error' => $e->getMessage()
            );
        // and fall back to showing the PARENT directory
        try
        {
            $rv = $this->_onView($dir, $jserr, $mime_filter, $list_type);
            echo json_encode($rv);
        }
        catch (Exception $e)
        {
            // and fall back to showing the BASEDIR directory
            try
            {
                $dir = $this->getDir();
                $rv = $this->_onView($dir, $jserr, $mime_filter, $list_type);
                echo json_encode($rv);
            }
            catch (Exception $e)
            {
                // when we fail here, it's pretty darn bad and nothing to it.
                // just push the error JSON as go.
                echo json_encode($jserr);
            }
        }
    }
  }

  /**
   * Process the 'download' event
   *
   * Send the file content of the specified file for download by the client.
   * Only files residing within the directory tree rooted by the
   * 'basedir' (options['directory']) will be allowed to be downloaded.
   *
   * Expected parameters:
   *
   * $_GET['file']          filepath of the file to be downloaded
   *
   * On errors a HTTP 403 error response will be sent instead.
   */
  protected function onDownload()
  {
    try
    {
        if (!$this->options['download'])
            throw new FileManagerException('disabled');
        if (empty($_GET['file']))
            throw new FileManagerException('nofile');
        if(strpos($_GET['file'],'../') !== false)
            throw new FileManagerException('nofile');
        if(strpos($_GET['file'],'./') !== false)
            throw new FileManagerException('nofile');

        // change the path to fit your websites document structure
        $path = $this->getDir($_GET['file'], 0, false, false);
        if (!is_file($path))
            throw new FileManagerException('nofile');

        $name = pathinfo($path, PATHINFO_FILENAME);
        $fileinfo = array(
            'file' => $path,
            'name' => $name
        );
        if (!empty($this->options['DownloadIsAuthenticated_cb']) && function_exists($this->options['DownloadIsAuthenticated_cb']) && !$this->options['DownloadIsAuthenticated_cb']($this, 'download', $fileinfo))
            throw new FileManagerException('authenticated');

        if ($fd = fopen($path, "r"))
        {
            $fsize = filesize($path);
            $path_parts = pathinfo($path);
            $ext = strtolower($path_parts["extension"]);
            switch ($ext)
            {
            case "pdf":
                header('Content-type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $path_parts["basename"] . '"'); // use 'attachment' to force a download
                break;

             // add here more headers for diff. extensions

            default;
                header('Content-type: application/octet-stream');
                header('Content-Disposition: filename="' . $path_parts["basename"] . '"');
            }
            header("Content-length: $fsize");
            header("Cache-control: private"); //use this to open files directly

            fpassthru($fd);
            fclose($fd);
        }
    }
    catch(FileManagerException $e)
    {
        // we don't care whether it's a 404, a 403 or something else entirely: we feed 'em a 403 and that's final!
        if (function_exists('send_response_status_header'))
        {
            send_response_status_header(403);
            echo $e->getMessage();
        }
        else
        {
            // no smarties detection whether we're running on fcgi or bare iron, we assume the latter:
            header('HTTP/1.0 403 Forbidden', true, 403);
            echo $e->getMessage();
        }
    }
    catch(Exception $e)
    {
        // we don't care whether it's a 404, a 403 or something else entirely: we feed 'em a 403 and that's final!
        if (function_exists('send_response_status_header'))
        {
            send_response_status_header(403);
            echo $e->getMessage();
        }
        else
        {
            // no smarties detection whether we're running on fcgi or bare iron, we assume the latter:
            header('HTTP/1.0 403 Forbidden', true, 403);
            echo $e->getMessage();
        }
    }
  }

  /**
   * Process the 'upload' event
   *
   * Process and store the uploaded file in the designated location.
   * Images will be resized when possible and applicable. A thumbnail image will also
   * be preproduced when possible.
   * Return a JSON encoded status of success or failure.
   *
   * Expected parameters:
   *
   * $_GET['directory']     path relative to basedir a.k.a. options['directory'] root
   *
   * $_FILES[]              the metadata for the uploaded file
   *
   * Errors will produce a JSON encoded error report, including at least two fields:
   *
   * status                  0 for error; nonzero for success
   *
   * error                   error message
   */
  protected function onUpload()
  {
    try
    {
      if (!$this->options['upload'])
        throw new FileManagerException('disabled');
      if (!Upload::exists('Filedata'))
        throw new FileManagerException('nofile');

      $dir = $this->getDir(!empty($this->get['directory']) ? $this->get['directory'] : null);
      $name = pathinfo($this->getName($_FILES['Filedata']['name'], $dir), PATHINFO_FILENAME);
      $fileinfo = array(
        'dir' => $dir,
        'name' => $name,
        'extension' => $this->options['safe'] && $name && in_array(strtolower(pathinfo($_FILES['Filedata']['name'], PATHINFO_EXTENSION)), array('exe', 'dll', 'php', 'php3', 'php4', 'php5', 'phps')) ? 'txt' : null,
        'size' => $this->options['maxUploadSize'],
        'mimes' => $this->getAllowedMimeTypes(),
        'ext2mime_map' => $this->getMimeTypeDefinitions(),
        'chmod' => $this->options['chmod']
      );
      if (!empty($this->options['UploadIsAuthenticated_cb']) && function_exists($this->options['UploadIsAuthenticated_cb']) && !$this->options['UploadIsAuthenticated_cb']($this, 'upload', $fileinfo))
        throw new FileManagerException('authenticated');

      $file = Upload::move('Filedata', $dir, $fileinfo);

      /*
       * NOTE: you /can/ (and should be able to, IMHO) upload 'overly large' image files to your site, but the thumbnailing process step
       *       happening here will fail; we have memory usage estimators in place to make the fatal crash a non-silent one, i,e, one
       *       where we still have a very high probability of NOT fatally crashing the PHP iunterpreter but catching a suitable exception
       *       instead.
       *       Having uploaded such huge images, a developer/somebody can always go in later and up the memory limit if the site admins
       *       feel it is deserved. Until then, no thumbnails of such images (though you /should/ be able to milkbox-view the real thing!)
       */
      if (FileManagerUtility::startsWith($this->getMimeType($file), 'image/') && !empty($this->get['resize']))
      {
        $img = new Image($file);
        $size = $img->getSize();
        // Image::resize() takes care to maintain the proper aspect ratio, so this is easy:
        if ($size['width'] > $this->options['maxImageSize'] || $size['height'] > $this->options['maxImageSize'])
          $img->resize($this->options['maxImageSize'], $this->options['maxImageSize'])->save();
        unset($img);
      }

      echo json_encode(array(
        'status' => 1,
        'name' => pathinfo($file, PATHINFO_BASENAME)
      ));
    }
    catch(UploadException $e)
    {
      echo json_encode(array(
        'status' => 0,
        'error' => class_exists('ValidatorException') ? strip_tags($e->getMessage()) : '${upload.' . $e->getMessage() . '}' // This is for Styx :)
      ));
    }
    catch(FileManagerException $e)
    {
        $emsg = explode(':', $e->getMessage(), 2);
        echo json_encode(array(
                'status' => 0,
                'error' => '${upload.' . $emsg[0] . '}' . (isset($emsg[1]) ? $emsg[1] : '')
            ));
    }
    catch(Exception $e)
    {
      // catching other severe failures; since this can be anything and should only happen in the direst of circumstances, we don't bother translating
      echo json_encode(array(
        'status' => 0,
        'error' => $e->getMessage()
      ));
    }
  }

  /**
   * Process the 'move' event (with is used by both move/copy and rename client side actions)
   *
   * Copy or move/rename a given file or directory and return a JSON encoded status of success
   * or failure.
   *
   * Expected parameters:
   *
   * $_POST['copy']            nonzero value means copy, zero or nil for move/rename
   *
   * Source filespec:
   *
   *   $_POST['directory']     path relative to basedir a.k.a. options['directory'] root
   *
   *   $_POST['file']          original name of the file/subdirectory to be renamed/copied
   *
   * Destination filespec:
   *
   *   $_POST['newDirectory']  path relative to basedir a.k.a. options['directory'] root
   *
   *   $_POST['name']          target name of the file/subdirectory to be renamed/copied
   *
   * Errors will produce a JSON encoded error report, including at least two fields:
   *
   * status                    0 for error; nonzero for success
   *
   * error                     error message
   */
  protected function onMove()
  {
    try
    {
        if (!$this->options['move'])
            throw new FileManagerException('disabled');
        if (empty($this->post['file']))
            throw new FileManagerException('nofile');

        $rename = empty($this->post['newDirectory']) && !empty($this->post['name']);
        $dir = $this->getDir(!empty($this->post['directory']) ? $this->post['directory'] : null);
        $file = pathinfo($this->post['file'], PATHINFO_BASENAME);

        $is_dir = is_dir($dir . $file);
        $fn = !empty($this->post['copy']) ? 'copy' : 'rename';

        $name = pathinfo($file, PATHINFO_FILENAME);
        $fileinfo = array(
            'dir' => $dir,
            'file' => $file,
            'name' => $name,
            'newdir' => (!empty($this->post['newDirectory']) ? $this->post['newDirectory'] : '(null)'),
            'newname' => (!empty($this->post['name']) ? $this->post['name'] : '(null)'),
            'rename' => $rename,
            'is_dir' => $is_dir,
            'function' => $fn
        );

        if (!$this->checkFile($dir . $file) || (!$rename && $is_dir))
            throw new FileManagerException('nofile');

        if (!empty($this->options['MoveIsAuthenticated_cb']) && function_exists($this->options['MoveIsAuthenticated_cb']) && !$this->options['MoveIsAuthenticated_cb']($this, 'move', $fileinfo))
            throw new FileManagerException('authenticated');

        if($rename || $is_dir)
        {
            if (empty($this->post['name']))
                throw new FileManagerException('nonewfile');

            $newname = $this->getName($this->post['name'], $dir);
            $fn = 'rename';
            if(!$is_dir)
            {
                if (!$this->deleteThumb($dir . $file))
                    throw new FileManagerException('delete_thumbnail_failed');
            }
        }
        else
        {
            $newname = $this->getName($file, $this->getDir($this->post['newDirectory']));
            //$fn = !empty($this->post['copy']) ? 'copy' : 'rename';
        }

        if (!$newname)
            throw new FileManagerException('nonewfile');

        $extOld = pathinfo($file, PATHINFO_EXTENSION);
        $extNew = pathinfo($newname, PATHINFO_EXTENSION);
        if ($extOld != $extNew) $newname .= '.' . $extOld;
        if (!@$fn($file, $newname))
            throw new FileManagerException($fn . '_failed');

        echo json_encode(array(
            'status' => 1,
            'name' => pathinfo(self::normalize($newname), PATHINFO_BASENAME)
        ));
    }
    catch(FileManagerException $e)
    {
        $emsg = explode(':', $e->getMessage(), 2);
        echo json_encode(array(
                'status' => 0,
                'error' => '${upload.' . $emsg[0] . '}' . (isset($emsg[1]) ? $emsg[1] : '')
            ));
    }
    catch(Exception $e)
    {
        // catching other severe failures; since this can be anything and should only happen in the direst of circumstances, we don't bother translating
        echo json_encode(array(
                'status' => 0,
                'error' => $e->getMessage()
            ));
    }
  }



  /**
   * Delete a file or directory, inclusing subdirectories and files.
   *
   * Return TRUE on success, FALSE when an error occurred.
   *
   * Note that the routine will try to percevere and keep deleting other subdirectories
   * and files, even when an error occurred for one or more of the subitems: this is
   * a best effort policy.
   */
  protected function unlink($file)
  {
    if (!$file || !FileManagerUtility::startsWith($file, $this->basedir))
        return false;

    $rv = true;
    if(is_dir($file))
    {
      $files = glob($file . '/*');
      if (is_array($files))
        foreach ($files as $f)
        {
          $rv &= $this->unlink($f);
          $rv &= $this->deleteThumb($f);
        }

      $rv &= @rmdir($file);
    }
    else
    {
      if (file_exists($file))
      {
        $rv &= @unlink($file);
        $rv &= $this->deleteThumb($file);
      }
    }
    return $rv;
  }

  /**
   * Make a unique filename
   *
   * Return the file (dir + name + ext), or a unique, yet non-existing, variant thereof, where the filename
   * is appended with a '_' and a number, e.g. '_1', when the file itself already exists in the given
   * directory. The directory part of the returned value equals $dir.
   *
   * Return NULL when $file is empty or when the specified directory does not reside within the
   * directory tree rooted by options['directory']
   *
   * Note that the given filename $file will be converted to a legal filename, containing a filesystem-legal
   * subset of ASCII characters only, before being used and returned by this function.
   */
  protected function getName($file, $dir)
  {
    if (!FileManagerUtility::endsWith($dir, '/')) $dir .= '/';

    if (!$file || !FileManagerUtility::startsWith($dir, $this->basedir)) return null;

    $pathinfo = pathinfo($file);

    /*
    since 'pagetitle()' is used to produce a unique, non-existing filename, we can forego the dirscan
    and simply check whether the constructed filename/path exists or not and bump the suffix number
    by 1 until it does not, thus quickly producing a unique filename.

    This is faster than using a dirscan to collect a set of existing filenames and feeding them as
    an option array to pagetitle(), particularly for large directories.
    */
    $filename = FileManagerUtility::pagetitle($pathinfo['filename']);
    // make sure the generated filename is SAFE:
    $file = $dir . $filename . (!empty($pathinfo['extension']) ? '.' . $pathinfo['extension'] : null);
    for ($i = 0; ; $i++)
    {
        $file = $dir . $filename . ($i ? '_' . $i : '') . (!empty($pathinfo['extension']) ? '.' . $pathinfo['extension'] : null);
        if (!file_exists($file))
            break;
    }

    return $file;
  }

  protected function getIcon($file, $smallIcon = false)
  {
    if (FileManagerUtility::endsWith($file, '/..')) $ext = 'dir_up';
    elseif (is_dir($file)) $ext = 'dir';
    else $ext = pathinfo($file, PATHINFO_EXTENSION);

    $largeDir = ($smallIcon === false ? 'Large/' : '');
    $path = (is_file(FileManagerUtility::getSiteRoot() . $this->options['assetBasePath'] . 'Images/Icons/' .$largeDir.$ext.'.png'))
      ? $this->options['assetBasePath'] . 'Images/Icons/'.$largeDir.$ext.'.png'
      : $this->options['assetBasePath'] . 'Images/Icons/'.$largeDir.'default.png';

    return $path;
  }

  protected function getThumb($file)
  {
    $thumb = $this->generateThumbName($file);
    $thumbPath = FileManagerUtility::getSiteRoot() . $this->options['thumbnailPath'] . $thumb;
    if (is_file($thumbPath))
      return $thumb;
    elseif(is_file(FileManagerUtility::getSiteRoot() . $this->options['thumbnailPath'] . basename($file)))
      return basename($file);
    else
      return $this->generateThumb($file,$thumbPath);
  }

  protected function generateThumbName($file)
  {
    return 'thumb_'.str_replace('.','_',basename($file)).'.png';
  }

  protected function generateThumb($file,$thumbPath)
  {
    $img = new Image($file);
    $img->resize(250,250,true,false)->process('png',$thumbPath); // TODO: save as lossy / lower-Q jpeg to reduce filesize?
    unset($img);
    return basename($thumbPath);
  }

  protected function deleteThumb($file)
  {
    $thumb = $this->generateThumbName($file);
    $thumbPath = FileManagerUtility::getSiteRoot() . $this->options['thumbnailPath'] . $thumb;
    if(is_file($thumbPath))
      return @unlink($thumbPath);
    return true;   // when thumbnail does not exist, say it is succesfully removed: all that counts is it doesn't exist anymore when we're done here.
  }

  public function getMimeType($file)
  {
    return is_dir($file) ? 'text/directory' : Upload::mime($file, null, $this->getMimeTypeDefinitions());
  }

  /**
   * Produce the absolute path equivalent, filesystem-wise, of the given $dir directory.
   *
   * The directory is enforced to sit within the directory tree rooted by options['directory']
   *
   * When the directory does not exist or does not match this restricting criterium, the
   * basedir path (abs path eqv. to options['directory']) is returned instead.
   *
   * In short: getDir() will guarantee the returned path equals the options['directory'] path or
   *           a subdirectory thereof. The returned path is an absolute path in the filesystem.
   */
  protected function getDir($dir = null, $chmod = 0777, $mkdir_if_notexist = false, $with_trailing_slash = true)
  {
    $dir = str_replace('\\','/', $dir);
    $basedir = $this->basedir;
	$root = FileManagerUtility::getSiteRoot();
    $dir = (!FileManagerUtility::startsWith($dir, '/') ? $basedir : $root . $dir);
    $dir = FileManagerUtility::getRealDir($dir, $chmod, $mkdir_if_notexist, $with_trailing_slash);
    return $this->checkFile($mkdir_if_notexist ? dirname($dir) : $dir) ? $dir : $this->basedir;
  }

  /**
   * Identical to getDir() apart from the fact that this method returns a DocumentRoot based abolute one.
   *
   * This function assumes the specified path is located within the options['directory'] a.k.a.
   * 'basedir' based directory tree.
   */
  protected function getPath($dir = null, $chmod = 0777, $mkdir_if_notexist = false, $with_trailing_slash = true)
  {
    $path = $this->getDir($dir, $chmod, $mkdir_if_notexist, $with_trailing_slash);
    $root = FileManagerUtility::getSiteRoot();
    $path = str_replace($root,'',$path);

    return $path;
  }

  /**
   * Determine whether the specified file or directory is not nil,
   * exists within the directory tree rooted by options['directory'] and
   * matches the permitted mimetypes restriction (optional $mime_filter)
   *
   * @return TRUE when all criteria are met, FALSE otherwise.
   */
  protected function checkFile($file, $mime_filter = null)
  {
    $mimes = $this->getAllowedMimeTypes($mime_filter);

    $hasFilter = ($mime_filter && count($mimes));
    if ($hasFilter) array_push($mimes, 'text/directory');
    return !empty($file) && FileManagerUtility::startsWith($file, $this->basedir) && file_exists($file) && (!$hasFilter || in_array($this->getMimeType($file), $mimes));
  }

  /**
   * Normalize a path by converting all slashes '/' and/or backslashes '\' and any mix thereof in the
   * specified path to UNIX/MAC/Win compatible single forward slashes '/'.
   */
  protected static function normalize($file)
  {
    return preg_replace('/(\\\|\/)+/', '/', $file);
  }

  public function getAllowedMimeTypes($mime_filter = null)
  {
    $mimeTypes = array();

    if (!$mime_filter) return null;
    if (!FileManagerUtility::endsWith($mime_filter, '/')) return array($mime_filter);

    $mimes = $this->getMimeTypeDefinitions();

    foreach ($mimes as $mime)
      if (FileManagerUtility::startsWith($mime, $mime_filter))
        $mimeTypes[] = strtolower($mime);

    return $mimeTypes;
  }

  public function getMimeTypeDefinitions()
  {
    static $mimes;

    if (!$mimes) $mimes = parse_ini_file($this->options['mimeTypesPath']);
    if (!$mimes) $mimes = array(); // prevent faulty mimetype ini file from b0rking other code sections.
    return $mimes;
  }
}

class FileManagerException extends Exception {}

/* Stripped-down version of some Styx PHP Framework-Functionality bundled with this FileBrowser. Styx is located at: http://styx.og5.net */
class FileManagerUtility
{
  public static function endsWith($string, $look){
    return strrpos($string, $look)===strlen($string)-strlen($look);
  }

  public static function startsWith($string, $look){
    return strpos($string, $look)===0;
  }

  /**
   * Check a given name $data against an optional set of names ($options array)
   * and return the name itself when it does not exist in the set,
   * otherwise return an augmented name such that it does not exist in the set
   * while having been constructed as name plus '_' plus an integer number,
   * starting at 1.
   *
   * Example:
   * If the set is {'file', 'file_1', 'file_3'} then $data = 'file' will return
   * the string 'file_2' instead, while $data = 'fileX' will return that same
   * value: 'fileX'.
   */
  public static function pagetitle($data, $options = array()){
    static $regex;
    if (!$regex){
      $regex = array(
        explode(' ', 'Æ æ Œ œ ß Ü ü Ö ö Ä ä À Á Â Ã Ä Å &#260; &#258; Ç &#262; &#268; &#270; &#272; Ð È É Ê Ë &#280; &#282; &#286; Ì Í Î Ï &#304; &#321; &#317; &#313; Ñ &#323; &#327; Ò Ó Ô Õ Ö Ø &#336; &#340; &#344; Š &#346; &#350; &#356; &#354; Ù Ú Û Ü &#366; &#368; Ý Ž &#377; &#379; à á â ã ä å &#261; &#259; ç &#263; &#269; &#271; &#273; è é ê ë &#281; &#283; &#287; ì í î ï &#305; &#322; &#318; &#314; ñ &#324; &#328; ð ò ó ô õ ö ø &#337; &#341; &#345; &#347; š &#351; &#357; &#355; ù ú û ü &#367; &#369; ý ÿ ž &#378; &#380;'),
        explode(' ', 'Ae ae Oe oe ss Ue ue Oe oe Ae ae A A A A A A A A C C C D D D E E E E E E G I I I I I L L L N N N O O O O O O O R R S S S T T U U U U U U Y Z Z Z a a a a a a a a c c c d d e e e e e e g i i i i i l l l n n n o o o o o o o o r r s s s t t u u u u u u y y z z z'),
      );
    }
    
    $data = trim(substr(preg_replace('/(?:[^A-z0-9]|_|\^)+/i', '_', str_replace($regex[0], $regex[1], $data)), 0, 64), '_');
    return !empty($options) ? self::checkTitle($data, $options) : $data;
  }

  protected static function checkTitle($data, $options = array(), $i = 0){
    if (!is_array($options)) return $data;

    $lwr_data = strtolower($data);

    foreach ($options as $content)
      if ($content && strtolower($content) == $lwr_data . ($i ? '_' . $i : ''))
        return self::checkTitle($data, $options, ++$i);

    return $data.($i ? '_' . $i : '');
  }

  public static function isBinary($str){
    $array = array(0, 255);
    for($i = 0; $i < strlen($str); $i++)
      if (in_array(ord($str[$i]), $array)) return true;

    return false;
  }

// unused method:
//
//  public static function getPath(){
//    static $path;
//    return $path ? $path : $path = pathinfo(str_replace('\\','/',__FILE__), PATHINFO_DIRNAME);
//  }

  /**
   * Return the filesystem absolute path to the directory pointed at by this site's DocumentRoot.
   *
   * Note that the path is returned WITHOUT a trailing slash '/'.
   */
  public static function getSiteRoot()
  {
    $path = str_replace('\\','/',$_SERVER['DOCUMENT_ROOT']);
    $path = (FileManagerUtility::endsWith($path,'/')) ? substr($path, 0, -1) : $path;

    return $path;
  }

  /**
   * Return the filesystem absolute path to the directory pointed at by the current URI request.
   * For example, if the request was 'http://site.org/dir1/dir2/script', then this method will
   * return '<DocumentRoot>/dir1/dir2' where <DocumentRoot> is the filesystem path pointing
   * at this site's DocumentRoot.
   *
   * Note that the path is returned WITHOUT a trailing slash '/'.
   */
  public static function getRequestDir()
  {
    // see also: http://php.about.com/od/learnphp/qt/_SERVER_PHP.htm
    $path = str_replace('\\','/', $_SERVER['SCRIPT_NAME']);
    $root = FileManagerUtility::getSiteRoot();
    $path = dirname(!FileManagerUtility::startsWith($path, $root) ? $root . (!FileManagerUtility::startsWith($path, '/') ? '/' : '') . $path : $path);
    $path = (FileManagerUtility::endsWith($path,'/')) ? substr($path, 0, -1) : $path;

    return $path;
  }

  /**
   * Convert any relative or absolute path to a fully sanitized absolute path relative to DocumentRoot.
   *
   * When fed malicious paths (paths pointing outside the DocumentRoot tree) or paths which do not exist, a suitable
   * Exception will be thrown.
   * Note however that if and only if the parent directory of the given path does exist, is legal, and the
   * $mkdir_if_notexist argument is TRUE, then the last name in the path specification will be treated as a
   * subdirectory which will be created, while setting the directory permissions to the $chmod specified
   * value (default: 0777)
   */
  public static function getRealPath($path, $chmod = 0777, $mkdir_if_notexist = false, $with_trailing_slash = true)
  {
    $path = preg_replace('/(\\\|\/)+/', '/', $path);
    $root = FileManagerUtility::getSiteRoot();
    $path = str_replace($root,'',$path);

    $path = (FileManagerUtility::startsWith($path,'/') ? $root . $path : FileManagerUtility::getRequestDir() . '/' . $path); /* do not base rel paths on FileManagerUtility::getRequestDir() root! */

    /*
     * fold '../' directory parts to prevent malicious paths such as 'a/../../../../../../../../../etc/'
     * from succeeding
     *
     * to prevent screwups in the folding code, we FIRST clean out the './' directories, to prevent
     * 'a/./.././.././.././.././.././.././.././.././../etc/' from succeeding:
     */
    $path = preg_replace('#/(\./)+#','/',$path);

    // now temporarily strip off the leading part up to the colon to prevent entries like '../d:/dir' to succeed when the site root is 'c:/', for example:
    $lead = '';
    // the leading part may NOT contain any directory separators, as it's for drive letters only.
    // So we must check in order to prevent malice like /../../../../../../../c:/dir from making it through.
    if (preg_match('#^([A-Za-z]:)?/(.*)$#', $path, $matches))
    {
        $lead = $matches[1];
        $path = '/' . $matches[2];
    }

    while (($pos = strpos($path, '/..')) !== false)
    {
        $prev = substr($path, 0, $pos);
        /*
         * on Windows, you get:
         *
         * dirname("/") = "\"
         * dirname("y/") = "."
         * dirname("/x") = "\"
         *
         * so we'd rather not use dirname()   :-(
         */
        $p2 = strrpos($prev, '/');
        if ($p2 === false)
        {
            throw new FileManagerException('path_tampering');
        }
        $prev = substr($prev, 0, $p2);
        $next = substr($path, $pos + 3);
        if ($next && $next[0] != '/')
        {
            throw new FileManagerException('path_tampering');
        }
        $path = $prev . $next;
    }

    $path = $lead . $path;

    /*
     * iff there was such a '../../../etc/' attempt, we'll know because the resulting path does NOT have
     * the 'siteroot' prefix. We don't cover up such 'mishaps' but throw a tantrum instead, so upper level
     * logic can process this fact accordingly:
     */
    if (!FileManagerUtility::startsWith($path, $root))
    {
        throw new FileManagerException('path_tampering');
    }

    if(!is_dir($path) && is_dir(dirname($path)) && $mkdir_if_notexist)
    {
        $rv = @mkdir($path,$chmod); // create last folder if not existing
        if ($rv === false)
        {
            throw new FileManagerException('mkdir_failed:' . $path);
        }
    }

    /*
     * now all there's left for realpath() to do is expand possible symbolic links in the path; don't make
     * that dependent on how the path looks:
     */
    $rv = realpath($path);
    if ($rv === false)
    {
        throw new FileManagerException('realpath_failed:' . $path);
    }
    $path = str_replace('\\','/',$rv);
    $path = str_replace($root,'',$path);
    $path = ($with_trailing_slash ? (FileManagerUtility::endsWith($path,'/') ? $path : $path.'/') : ((FileManagerUtility::endsWith($path,'/') && strlen($path) > 1) ? substr($path, 0, -1) : $path));

    return $path;
  }

  /**
   * Return the filesystem absolute path equivalent of the output of getRealPath().
   */
  public static function getRealDir($path, $chmod = 0777, $mkdir_if_notexist = false, $with_trailing_slash = true)
  {
    $path = self::getRealPath($path, $chmod, $mkdir_if_notexist, $with_trailing_slash);
    $path = FileManagerUtility::getSiteRoot() . $path;
    return $path;
  }

  /**
   * Apply rawurlencode() to each of the elements of the given path
   *
   * @note
   *   this method is provided as rawurlencode() tself also encodes the '/' separators in a path/string
   *   and we do NOT want to 'revert' such change with the risk of also picking up other %2F bits in
   *   the string (this assumes crafted paths can be fed to us).
   */
  public static function rawurlencode_path($path)
  {
    $encoded_path = explode('/', $path);
    array_walk($encoded_path, function(&$value, $key)
        {
            $value = rawurlencode($value);
        });
    return implode('/', $encoded_path);
  }
  
  /**
   * Convert a number (representing number of bytes) to a formatted string representing GB .. bytes, 
   * depending on the size of the value.
   */
  public static function fmt_bytecount($val, $precision = 1) 
  {
    $unit = array('TB', 'GB', 'MB', 'KB', 'bytes');
    for ($x = count($unit) - 1; $val >= 1024 && $x > 0; $x--)
	{ 
		$val /= 1024.0; 
	}
    $val = round($val, ($x > 0 ? $precision : 0));
	return $val . '&#160;' . $unit[$x];
  }
}