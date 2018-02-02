<?php

/*
As AJAX calls cannot set cookies, we set up the session for the authentication demonstration right here; that way, the session cookie
will travel with every request.
*/
session_name('alt_session_name');
if (!session_start()) die('session_start() failed');

/*
set a 'secret' value to doublecheck the legality of the session: did it originate from here?
*/
$_SESSION['FileManager'] = 'DemoMagick';

/*
Note that for the sake of the demo, we simulate an UNauthorized user in the session.
*/
$_SESSION['UploadAuth'] = 'NO';


/* the remainder of the code does not need access to the session data. */
session_write_close();

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<title>MooTools FileManager TinyMCE example</title>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<link rel="stylesheet" href="demos.css" type="text/css" />

	<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/4.7.6/tinymce.min.js"></script>

<!--
  <script type="text/javascript" src="mootools-core.js"></script>
  <script type="text/javascript" src="mootools-more.js"></script>
-->
  <script type="text/javascript" src="MooTools-Core-1.6.0.js"></script>
  <script type="text/javascript" src="MooTools-More-1.6.0.js"></script>
  
	<script type="text/javascript">
		// disable the autoinit of the milkbox (must be set before the FileManager.js loads the milkbox.js!)
		__MILKBOX_NO_AUTOINIT__ = true;
	</script>

	<script type="text/javascript" src="../Source/FileManager.js"></script>
	<script type="text/javascript" src="../Source/NoFlash.Uploader.js"></script>
	<script type="text/javascript" src="../Language/Language.en.js"></script>
	<script type="text/javascript" src="../Language/Language.de.js"></script>

	<script type="text/javascript" src="../Source/FileManager.TinyMCE.js"></script>

	<script type="text/javascript">
		tinyMCE.init({
			selector: 'textarea',
			theme: 'modern',
			plugins: 'print preview fullpage searchreplace autolink directionality code visualblocks visualchars fullscreen image link media template codesample table charmap hr pagebreak nonbreaking anchor toc insertdatetime advlist lists textcolor wordcount imagetools  contextmenu colorpicker textpattern help',
      toolbar1: 'formatselect | bold italic strikethrough forecolor backcolor | link | alignleft aligncenter alignright alignjustify  | numlist bullist outdent indent  | removeformat',
      image_advtab: true,
			width: '100%',
			height: '300px',

			/* Here goes the Magic */
			file_browser_callback: FileManager.TinyMCE(function(type)
			{
				if (typeof this.milkbox == 'undefined')
				{
					// init the milkbox: we cannot use the zIndex base set by the FileManager as the FM isn't initialized yet!
					this.milkbox = new Milkbox({
						centered: true,
						zIndex: 400000 + 4000,
						//autoSizeMaxHeight: 0,
						//autoSizeMaxWidth: 0,
						autoSizeMinHeight: 60,
						autoSizeMinWidth: 100,      // compensate for very small images: always show the controls, at least
						marginTop: 10
					});
				}

				return {
					url: 'manager.php?exhibit=A', // 'manager.php', but with a bogus query parameter included: latest FM can cope with such an URI
					assetBasePath: '../Assets', // '/c/lib/includes/js/mootools-filemanager/Assets',
					language: 'en',
					selectable: true,
					destroy: true,
					upload: true,
					rename: true,
					move_or_copy: true,
					download: true,
					createFolders: true,
					hideClose: false,
					hideOverlay: false,
					// uploadAuthData is deprecated; use propagateData instead. The session cookie(s) are passed through Flash automatically, these days...
					uploadAuthData: {
						session: 'MySessionData'
					},
					// and a couple of extra user defined parameters sent with EVERY request:
					propagateData: {
						editor_reqtype: type,
						origin: 'demo-tinyMCE'
					}
				};
			})
		});

		window.addEvent('domready', function(){
			$('getEditorText').addEvent('click', function(e){
				e.stop();
				$('editorContent').set('html', tinyMCE.activeEditor.getContent());
			});
		});
	</script>
</head>
<body>
<div id="content" class="content">
	<div class="go_home">
	<a href="index.php" title="Go to the Demo index page"><img src="home_16x16.png"> </a>
	</div>

	<h1>FileManager Demo</h1>

	<div style="clear: both;">
		<textarea>Add an image or a link to a file!</textarea>
		<button id="getEditorText" name="getEditorTextButton">Get editor content</button>
	</div>
	<div id="editorContent"></div>
</div>
</body>
</html>