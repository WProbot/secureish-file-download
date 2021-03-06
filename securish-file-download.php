<?php
/*
Plugin Name: Secure(ish) File Downloads
Plugin URI: 
Description: Semi-secure file downloads. Require uses to login and hide URLs behind a hash. Based on the Filedownload plugin by <a href="http://www.worldweb-innovation.de/">Peter Gross</a>.
Version: 0.1
Author: Anthony Cole, Brent Shepherd
Author URI: http://find.brentshepherd.com

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.

*/

Secure_File_Download::init();

class Secure_File_Download {

	/**
	 * Our init action - this action fires off all actions and brings the shortcode to life.
	 *
	 * @return void
	 * @author Anthony Cole
	 **/
	public static function init() {

		add_shortcode( 'secure_download', __CLASS__ . '::shortcode_handler' );

		add_action( 'save_post', __CLASS__ . '::save_download_details', 10, 2 );

		add_action( 'template_redirect', __CLASS__ . '::do_download' );

		add_action( 'init', __CLASS__ . '::add_language_files' );

		add_action( 'init', __CLASS__ . '::flush_rules' );

		add_filter( 'query_vars', __CLASS__ . '::query_var' );

		add_action( 'generate_rewrite_rules', __CLASS__ . '::add_rewrite_rules' );
	}


	/**
	 * Store the file's full path/URI and other details when the post is saved.
	 * 
	 * Although it requires manually parsing the shortcode in content, it is efficient to perform this 
	 * database write only when the shortcode has potentially changed (when a post is saved) rather than 
	 * doing it for every page view in the shortcode handler.
	 */
	public static function save_download_details( $post_id, $post ){
		global $shortcode_tags;

		$matches = array();

		$original_tags  = $shortcode_tags;
		$shortcode_tags = array( 'secure_download' => __CLASS__ . '::shortcode_handler' );
		preg_match_all( '/' . get_shortcode_regex() . '/', $post->post_content, $matches );
		$shortcode_tags = $original_tags;

		if( isset( $matches[3] ) && is_array( $matches[3] ) ) {

			foreach( $matches[3] as $attributes ) {

				extract( self::get_attributes( shortcode_parse_atts( $attributes ) ) );

				if( $hash_permalink === true || $hash_permalink === 'true'  ) {
					$filename = md5( $file . NONCE_SALT );
				} else {
					$path_parts = pathinfo( $file );
					$extension  = $path_parts['extension'];
					$filename   = $path_parts['filename'].'.'.$path_parts['extension'];
				}

				update_option( $filename, array( 'file' => $file, 'login_required' => $login_required ) ); // Specifically not updating the option on every page load to save on DB loads
			}
		}
	}


	/**
	 * Generate the hashed download link and replace the [download file=""] shortcode.
	 * 
	 * @param atts array Contains the download links attributes, which includes:
	 *             'file' string Either the URL or file system path to the file.
	 *             'login_required' boolean Whether a user must be logged in to download the file. Default true.
	 *             'auto_download' integer The time in milliseconds after which the file should begin downloading automatically eg. 5000 will download the file after 5 seconds. Default 0 - never download automatically.
	 * @return string an anchor tag with with hashed download link
	 * @author Brent Shepherd
	 */
	public static function shortcode_handler( $atts, $content = null ) {

		extract( self::get_attributes( $atts ) );

		if ( $file == '' )
			return '<b>' . __( 'Secure File Download Error: parameter file is empty!', 'sfd' ) . '</b>';

		if ( $content == null )
			$content = __( 'Download', 'sfd' );

		$attributes  = ( $id != '' )    ? " id='$id'" : '';
		$attributes .= ( $class != '' ) ? " class='$class'" : '';
		$attributes .= ( $style != '' ) ? " style='$style'" : '';

		$download_link = sprintf( '<a%s href="%s">%s</a>', $attributes, site_url( 'secure-download/' . $filename ), do_shortcode( $content ) );

		if( $auto_download != 0 ) {
			if( $id != '' )
				$download_link .= '<script>window.setTimeout(function(){location.href = document.getElementById("'.$id.'").href;}, '.$auto_download.');</script>';
			else
				return sprintf( __( '%sSecure File Download Error:%s auto download requires either an ID to be specified.', 'sfd' ), '<b>', '</b>' );
		}

		return $download_link;
	}


	/**
	 * Outputs the file if the URL includes a valid hash.
	 *
	 * @return void
	 * @author Anthony Cole
	 **/
	public static function do_download() {
		global $wp_query;

		if( ! isset( $wp_query->query_vars['secure-download'] ) )
			return;

		$secure_download = get_option( $wp_query->query_vars['secure-download'] );

		if( empty( $secure_download['file'] ) )
			wp_die( sprintf( __( 'Secure File Download Error: no path provided. Did you specify a %sfile=""%s parameter in the shortcode?', 'sfd' ), '<code>', '</code>' ) );

		if( ( $secure_download['login_required'] === true || $secure_download['login_required'] == 'true' ) && ! is_user_logged_in() ) {
			$redirect_to = ( wp_get_referer() == false ) ? site_url( 'secure-download/' . $wp_query->query_vars['secure-download'] ) : wp_get_referer();
			wp_die( sprintf( __( 'Secure File Download Error: you must be logged in to download this file. %sLogin%s', 'sfd' ), '<a href="'.wp_login_url( $redirect_to ) . '">', '&nbsp;&raquo;</a>' ) );
		}

		$path_parts = pathinfo( $secure_download['file'] );
		$extension  = $path_parts['extension'];
		$filename   = $path_parts['filename'].'.'.$path_parts['extension'];

		$type = self::get_mime_type( $extension );

		if( ( $open = @fopen( $secure_download['file'], 'r' ) ) === false )
			wp_die( sprintf( __( 'Secure File Download Error: file %s does not exist!', 'sfd' ), '<code>' . $secure_download['file'] . '</code>' ) );

		fclose( $open );

		// Start download
		header( "Content-Type: $type" );
		header( "Content-Disposition: attachment; filename=\"$filename\"" );
		readfile( $secure_download['file'] );

		// Write download count stat to database
		$download_count = get_option( 'sfd_download_count', array() );

		$download_count[$path_parts['filename']] = ( isset( $download_count[$path_parts['filename']] ) ) ? $download_count[$path_parts['filename']]++ : 1;

		update_option( 'sfd_download_count', $download_count );

		exit();
	}


	/**
	 * Parses attributes with the default attribute and also builds a filename depending on 
	 * whether the shortcode requests a hashed download link or not.
	 * 
	 * Abstracted into a function as this is done both when a post is saved and when the shortcode
	 * markup is created. 
	 *
	 * @return array $attributes 
	 * @author Brent Shepherd
	 */
	private static function get_attributes( $atts ) {
		$attributes = shortcode_atts( array(
										'file'           => '',
										'id'             => '',
										'style'          => '',
										'class'          => '',
										'login_required' => 'true',
										'hash_permalink' => 'false',
										'auto_download'  => 0 ),
										$atts );

		if( $attributes['hash_permalink'] === true || $attributes['hash_permalink'] === 'true'  ) {
			$attributes['filename'] = md5( $attributes['file'] . NONCE_SALT );
		} else {
			$attributes['path_parts'] = pathinfo( $attributes['file'] );
			$attributes['extension']  = $attributes['path_parts']['extension'];
			$attributes['filename']   = $attributes['path_parts']['filename'].'.'.$attributes['path_parts']['extension'];
		}

		return $attributes;
	}


	/**
	 * Add language file
	 *
	 * @return void
	 * @author Anthony Cole
	 **/
	public static function add_language_files() {
		load_plugin_textdomain( 'sfd' );
	}


	/**
	 * Adds the 'secure-download' rewrite tag to WordPress.
	 *
	 * @author Brent Shepherd
	 */
	public static function query_var( $vars ) {
		$vars[] = 'secure-download';
		return $vars;
	}


	/**
	 * Tell WordPress how to handle requests for the 'secure-download' rewrite tag.
	 *
	 * @author Brent Shepherd
	 */
	public static function add_rewrite_rules( $wp_rewrite ) {
		$new_rules = array( 'secure-download/(.*)' => 'index.php?secure-download=' . $wp_rewrite->preg_index(1) );
		$wp_rewrite->rules = $new_rules + $wp_rewrite->rules;
	}


	/**
	 * Flush rewrite rules if the secure-download rule is not included.
	 *
	 * @author Brent Shepherd
	 */
	public static function flush_rules() {
		$rules = get_option( 'rewrite_rules' );

		if ( ! isset( $rules['secure-download/(.*)'] ) ) {
			global $wp_rewrite;
			$wp_rewrite->flush_rules();
		}
	}


	/**
	 * Get the full mime-type string associated with a given extension.
	 *
	 * @author Brent Shepherd
	 */
	public static function get_mime_type( $extension ) {
		
		switch ( $extension ) {
			case "3dm": return "x-world/x-3dmf";
			case "3dmf": return "x-world/x-3dmf";
			case "a": return "application/octet-stream";
			case "aab": return "application/x-authorware-bin";
			case "aam": return "application/x-authorware-map";
			case "aas": return "application/x-authorware-seg";
			case "abc": return "text/vnd";    
			case "acgi": return "text/html";
			case "afl": return "video/animaflex";
			case "ai": return "application/postscript";
			case "aif": return "audio/aiff";
			case "aif": return "audio/x-aiff";
			case "aifc": return "audio/aiff";
			case "aifc": return "audio/x-aiff";
			case "aiff": return "audio/aiff";
			case "aiff": return "audio/x-aiff";
			case "aim": return "application/x-aim";
			case "aip": return "text/x-audiosoft-intra";
			case "ani": return "application/x-navi-animation";
			case "aos": return "application/x-nokia-9000-communicator-add-on-software";
			case "aps": return "application/mime";
			case "arc": return "application/octet-stream";
			case "arj": return "application/arj";
			case "arj": return "application/octet-stream";
			case "art": return "image/x-jg";
			case "asf": return "video/x-ms-asf";
			case "asm": return "text/x-asm";
			case "asp": return "text/asp";
			case "asx": return "application/x-mplayer2";
			case "asx": return "video/x-ms-asf";
			case "asx": return "video/x-ms-asf-plugin";
			case "au": return "audio/basic";
			case "au": return "audio/x-au";
			case "avi": return "application/x-troff-msvideo";
			case "avi": return "video/avi";
			case "avi": return "video/msvideo";
			case "avi": return "video/x-msvideo";
			case "avs": return "video/avs-video";
			case "bcpio": return "application/x-bcpio";
			case "bin": return "application/mac-binary";
			case "bin": return "application/macbinary";
			case "bin": return "application/octet-stream";
			case "bin": return "application/x-binary";
			case "bin": return "application/x-macbinary";
			case "bm": return "image/bmp";
			case "bmp": return "image/bmp";
			case "bmp": return "image/x-windows-bmp";
			case "boo": return "application/book";
			case "book": return "application/book";
			case "boz": return "application/x-bzip2";
			case "bsh": return "application/x-bsh";
			case "bz": return "application/x-bzip";
			case "bz2": return "application/x-bzip2";
			case "c": return "text/plain";
			case "c": return "text/x-c";
			case "c++": return "text/plain";
			case "cat": return "application/vnd";    
			case "cc": return "text/plain";
			case "cc": return "text/x-c";
			case "ccad": return "application/clariscad";
			case "cco": return "application/x-cocoa";
			case "cdf": return "application/cdf";
			case "cdf": return "application/x-cdf";
			case "cdf": return "application/x-netcdf";
			case "cer": return "application/pkix-cert";
			case "cer": return "application/x-x509-ca-cert";
			case "cha": return "application/x-chat";
			case "chat": return "application/x-chat";
			case "class": return "application/java";
			case "class": return "application/java-byte-code";
			case "class": return "application/x-java-class";
			case "com": return "application/octet-stream";
			case "com": return "text/plain";
			case "conf": return "text/plain";
			case "cpio": return "application/x-cpio";
			case "cpp": return "text/x-c";
			case "cpt": return "application/mac-compactpro";
			case "cpt": return "application/x-compactpro";
			case "cpt": return "application/x-cpt";
			case "crl": return "application/pkcs-crl";
			case "crl": return "application/pkix-crl";
			case "crt": return "application/pkix-cert";
			case "crt": return "application/x-x509-ca-cert";
			case "crt": return "application/x-x509-user-cert";
			case "csh": return "application/x-csh";
			case "csh": return "text/x-script";
			case "css": return "application/x-pointplus";
			case "css": return "text/css";
			case "cxx": return "text/plain";
			case "dcr": return "application/x-director";
			case "deepv": return "application/x-deepv";
			case "def": return "text/plain";
			case "der": return "application/x-x509-ca-cert";
			case "dif": return "video/x-dv";
			case "dir": return "application/x-director";
			case "dl": return "video/dl";
			case "dl": return "video/x-dl";
			case "doc": return "application/msword";
			case "dot": return "application/msword";
			case "dp": return "application/commonground";
			case "drw": return "application/drafting";
			case "dump": return "application/octet-stream";
			case "dv": return "video/x-dv";
			case "dvi": return "application/x-dvi";
			case "dwf": return "drawing/x-dwf";
			case "dwf": return "model/vnd";    
			case "dwg": return "application/acad";
			case "dwg": return "image/vnd";
			case "dwg": return "image/x-dwg";
			case "dxf": return "application/dxf";
			case "dxf": return "image/vnd";
			case "dxf": return "image/x-dwg";
			case "dxr": return "application/x-director";
			case "el": return "text/x-script";    
			case "elc": return "application/x-bytecode";
			case "elc": return "application/x-elc";
			case "env": return "application/x-envoy";
			case "eps": return "application/postscript";
			case "es": return "application/x-esrehber";
			case "etx": return "text/x-setext";
			case "evy": return "application/envoy";
			case "evy": return "application/x-envoy";
			case "exe": return "application/octet-stream";
			case "f": return "text/plain";
			case "f": return "text/x-fortran";
			case "f77": return "text/x-fortran";
			case "f90": return "text/plain";
			case "f90": return "text/x-fortran";
			case "fdf": return "application/vnd";
			case "fif": return "application/fractals";
			case "fif": return "image/fif";
			case "fli": return "video/fli";
			case "fli": return "video/x-fli";
			case "flo": return "image/florian";
			case "flx": return "text/vnd";
			case "fmf": return "video/x-atomic3d-feature";
			case "for": return "text/plain";
			case "for": return "text/x-fortran";
			case "fpx": return "image/vnd";
			case "fpx": return "image/vnd";
			case "frl": return "application/freeloader";
			case "funk": return "audio/make";
			case "g": return "text/plain";
			case "g3": return "image/g3fax";
			case "gif": return "image/gif";
			case "gl": return "video/gl";
			case "gl": return "video/x-gl";
			case "gsd": return "audio/x-gsm";
			case "gsm": return "audio/x-gsm";
			case "gsp": return "application/x-gsp";
			case "gss": return "application/x-gss";
			case "gtar": return "application/x-gtar";
			case "gz": return "application/x-compressed";
			case "gz": return "application/x-gzip";
			case "gzip": return "application/x-gzip";
			case "gzip": return "multipart/x-gzip";
			case "h": return "text/plain";
			case "h": return "text/x-h";
			case "hdf": return "application/x-hdf";
			case "help": return "application/x-helpfile";
			case "hgl": return "application/vnd";
			case "hh": return "text/plain";
			case "hh": return "text/x-h";
			case "hlb": return "text/x-script";
			case "hlp": return "application/hlp";
			case "hlp": return "application/x-helpfile";
			case "hlp": return "application/x-winhelp";
			case "hpg": return "application/vnd";
			case "hpgl": return "application/vnd";
			case "hqx": return "application/binhex";
			case "hqx": return "application/binhex4";
			case "hqx": return "application/mac-binhex";
			case "hqx": return "application/mac-binhex40";
			case "hqx": return "application/x-binhex40";
			case "hqx": return "application/x-mac-binhex40";
			case "hta": return "application/hta";
			case "htc": return "text/x-component";
			case "htm": return "text/html";
			case "html": return "text/html";
			case "htmls": return "text/html";
			case "htt": return "text/webviewhtml";
			case "htx": return "text/html";
			case "ice": return "x-conference/x-cooltalk";
			case "ico": return "image/x-icon";
			case "idc": return "text/plain";
			case "ief": return "image/ief";
			case "iefs": return "image/ief";
			case "iges": return "application/iges";
			case "iges": return "model/iges";
			case "igs": return "application/iges";
			case "igs": return "model/iges";
			case "ima": return "application/x-ima";
			case "imap": return "application/x-httpd-imap";
			case "inf": return "application/inf";
			case "ins": return "application/x-internett-signup";
			case "ip": return "application/x-ip2";
			case "isu": return "video/x-isvideo";
			case "it": return "audio/it";
			case "iv": return "application/x-inventor";
			case "ivr": return "i-world/i-vrml";
			case "ivy": return "application/x-livescreen";
			case "jam": return "audio/x-jam";
			case "jav": return "text/plain";
			case "jav": return "text/x-java-source";
			case "java": return "text/plain";
			case "java": return "text/x-java-source";
			case "jcm": return "application/x-java-commerce";
			case "jfif": return "image/jpeg";
			case "jfif": return "image/pjpeg";
			case "jfif-tbnl": return "image/jpeg";
			case "jpe": return "image/jpeg";
			case "jpe": return "image/pjpeg";
			case "jpeg": return "image/jpeg";
			case "jpeg": return "image/pjpeg";
			case "jpg": return "image/jpeg";
			case "jpg": return "image/pjpeg";
			case "jps": return "image/x-jps";
			case "js": return "application/x-javascript";
			case "jut": return "image/jutvision";
			case "kar": return "audio/midi";
			case "kar": return "music/x-karaoke";
			case "ksh": return "application/x-ksh";
			case "ksh": return "text/x-script";
			case "la": return "audio/nspaudio";
			case "la": return "audio/x-nspaudio";
			case "lam": return "audio/x-liveaudio";
			case "latex": return "application/x-latex";
			case "lha": return "application/lha";
			case "lha": return "application/octet-stream";
			case "lha": return "application/x-lha";
			case "lhx": return "application/octet-stream";
			case "list": return "text/plain";
			case "lma": return "audio/nspaudio";
			case "lma": return "audio/x-nspaudio";
			case "log": return "text/plain";
			case "lsp": return "application/x-lisp";
			case "lsp": return "text/x-script";
			case "lisp": return "text/x-script";
			case "lst": return "text/plain";
			case "lsx": return "text/x-la-asf";
			case "ltx": return "application/x-latex";
			case "lzh": return "application/octet-stream";
			case "lzh": return "application/x-lzh";
			case "lzx": return "application/lzx";
			case "lzx": return "application/octet-stream";
			case "lzx": return "application/x-lzx";
			case "m": return "text/plain";
			case "m": return "text/x-m";
			case "m1v": return "video/mpeg";
			case "m2a": return "audio/mpeg";
			case "m2v": return "video/mpeg";
			case "m3u": return "audio/x-mpequrl";
			case "man": return "application/x-troff-man";
			case "map": return "application/x-navimap";
			case "mar": return "text/plain";
			case "mbd": return "application/mbedlet";
			case "mc$": return "application/x-magic-cap-package-1";
			case "mcd": return "application/mcad";
			case "mcd": return "application/x-mathcad";
			case "mcf": return "image/vasa";
			case "mcf": return "text/mcf";
			case "mcp": return "application/netmc";
			case "me": return "application/x-troff-me";
			case "mht": return "message/rfc822";
			case "mhtml": return "message/rfc822";
			case "mid": return "application/x-midi";
			case "mid": return "audio/midi";
			case "mid": return "audio/x-mid";
			case "mid": return "audio/x-midi";
			case "mid": return "music/crescendo";
			case "mid": return "x-music/x-midi";
			case "midi": return "application/x-midi";
			case "midi": return "audio/midi";
			case "midi": return "audio/x-mid";
			case "midi": return "audio/x-midi";
			case "midi": return "music/crescendo";
			case "midi": return "x-music/x-midi";
			case "mif": return "application/x-frame";
			case "mif": return "application/x-mif";
			case "mime": return "message/rfc822";
			case "mime": return "www/mime";
			case "mjf": return "audio/x-vnd";
			case "mjpg": return "video/x-motion-jpeg";
			case "mm": return "application/base64";
			case "mm": return "application/x-meme";
			case "mme": return "application/base64";
			case "mod": return "audio/mod";
			case "mod": return "audio/x-mod";
			case "moov": return "video/quicktime";
			case "mov": return "video/quicktime";
			case "movie": return "video/x-sgi-movie";
			case "mp2": return "audio/mpeg";
			case "mp2": return "audio/x-mpeg";
			case "mp2": return "video/mpeg";
			case "mp2": return "video/x-mpeg";
			case "mp2": return "video/x-mpeq2a";
			case "mp3": return "audio/mpeg3";
			case "mp3": return "audio/x-mpeg-3";
			case "mp3": return "video/mpeg";
			case "mp3": return "video/x-mpeg";
			case "mpa": return "audio/mpeg";
			case "mpa": return "video/mpeg";
			case "mpc": return "application/x-project";
			case "mpe": return "video/mpeg";
			case "mpeg": return "video/mpeg";
			case "mpg": return "audio/mpeg";
			case "mpg": return "video/mpeg";
			case "mpga": return "audio/mpeg";
			case "mpp": return "application/vnd";
			case "mpt": return "application/x-project";
			case "mpv": return "application/x-project";
			case "mpx": return "application/x-project";
			case "mrc": return "application/marc";
			case "ms": return "application/x-troff-ms";
			case "mv": return "video/x-sgi-movie";
			case "my": return "audio/make";
			case "mzz": return "application/x-vnd";
			case "nap": return "image/naplps";
			case "naplps": return "image/naplps";
			case "nc": return "application/x-netcdf";
			case "ncm": return "application/vnd";
			case "nif": return "image/x-niff";
			case "niff": return "image/x-niff";
			case "nix": return "application/x-mix-transfer";
			case "nsc": return "application/x-conference";
			case "nvd": return "application/x-navidoc";
			case "o": return "application/octet-stream";
			case "oda": return "application/oda";
			case "omc": return "application/x-omc";
			case "omcd": return "application/x-omcdatamaker";
			case "omcr": return "application/x-omcregerator";
			case "p": return "text/x-pascal";
			case "p10": return "application/pkcs10";
			case "p10": return "application/x-pkcs10";
			case "p12": return "application/pkcs-12";
			case "p12": return "application/x-pkcs12";
			case "p7a": return "application/x-pkcs7-signature";
			case "p7c": return "application/pkcs7-mime";
			case "p7c": return "application/x-pkcs7-mime";
			case "p7m": return "application/pkcs7-mime";
			case "p7m": return "application/x-pkcs7-mime";
			case "p7r": return "application/x-pkcs7-certreqresp";
			case "p7s": return "application/pkcs7-signature";
			case "part": return "application/pro_eng";
			case "pas": return "text/pascal";
			case "pbm": return "image/x-portable-bitmap";
			case "pcl": return "application/vnd";
			case "pcl": return "application/x-pcl";
			case "pct": return "image/x-pict";
			case "pcx": return "image/x-pcx";
			case "pdb": return "chemical/x-pdb";
			case "pdf": return "application/pdf";
			case "pfunk": return "audio/make";
			case "pfunk": return "audio/make";
			case "pgm": return "image/x-portable-graymap";
			case "pgm": return "image/x-portable-greymap";
			case "pic": return "image/pict";
			case "pict": return "image/pict";
			case "pkg": return "application/x-newton-compatible-pkg";
			case "pko": return "application/vnd";
			case "pl": return "text/plain";
			case "pl": return "text/x-script";
			case "plx": return "application/x-pixclscript";
			case "pm": return "image/x-xpixmap";
			case "pm": return "text/x-script";
			case "pm4": return "application/x-pagemaker";
			case "pm5": return "application/x-pagemaker";
			case "png": return "image/png";
			case "pnm": return "application/x-portable-anymap";
			case "pnm": return "image/x-portable-anymap";
			case "pot": return "application/mspowerpoint";
			case "pot": return "application/vnd";
			case "pov": return "model/x-pov";
			case "ppa": return "application/vnd";
			case "ppm": return "image/x-portable-pixmap";
			case "pps": return "application/mspowerpoint";
			case "pps": return "application/vnd";
			case "ppt": return "application/mspowerpoint";
			case "ppt": return "application/powerpoint";
			case "ppt": return "application/vnd";
			case "ppt": return "application/x-mspowerpoint";
			case "ppz": return "application/mspowerpoint";
			case "pre": return "application/x-freelance";
			case "prt": return "application/pro_eng";
			case "ps": return "application/postscript";
			case "psd": return "application/octet-stream";
			case "pvu": return "paleovu/x-pv";
			case "pwz": return "application/vnd";
			case "py": return "text/x-script";
			case "pyc": return "applicaiton/x-bytecode";
			case "qcp": return "audio/vnd";
			case "qd3": return "x-world/x-3dmf";
			case "qd3d": return "x-world/x-3dmf";
			case "qif": return "image/x-quicktime";
			case "qt": return "video/quicktime";
			case "qtc": return "video/x-qtc";
			case "qti": return "image/x-quicktime";
			case "qtif": return "image/x-quicktime";
			case "ra": return "audio/x-pn-realaudio";
			case "ra": return "audio/x-pn-realaudio-plugin";
			case "ra": return "audio/x-realaudio";
			case "ram": return "audio/x-pn-realaudio";
			case "ras": return "application/x-cmu-raster";
			case "ras": return "image/cmu-raster";
			case "ras": return "image/x-cmu-raster";
			case "rast": return "image/cmu-raster";
			case "rexx": return "text/x-script";
			case "rf": return "image/vnd";
			case "rgb": return "image/x-rgb";
			case "rm": return "application/vnd";
			case "rm": return "audio/x-pn-realaudio";
			case "rmi": return "audio/mid";
			case "rmm": return "audio/x-pn-realaudio";
			case "rmp": return "audio/x-pn-realaudio";
			case "rmp": return "audio/x-pn-realaudio-plugin";
			case "rng": return "application/ringing-tones";
			case "rng": return "application/vnd";
			case "rnx": return "application/vnd";
			case "roff": return "application/x-troff";
			case "rp": return "image/vnd";
			case "rpm": return "audio/x-pn-realaudio-plugin";
			case "rt": return "text/richtext";
			case "rt": return "text/vnd";
			case "rtf": return "application/rtf";
			case "rtf": return "application/x-rtf";
			case "rtf": return "text/richtext";
			case "rtx": return "application/rtf";
			case "rtx": return "text/richtext";
			case "rv": return "video/vnd";
			case "s": return "text/x-asm";
			case "s3m": return "audio/s3m";
			case "saveme": return "application/octet-stream";
			case "sbk": return "application/x-tbook";
			case "scm": return "application/x-lotusscreencam";
			case "scm": return "text/x-script";
			case "scm": return "text/x-script";
			case "scm": return "video/x-scm";
			case "sdml": return "text/plain";
			case "sdp": return "application/sdp";
			case "sdp": return "application/x-sdp";
			case "sdr": return "application/sounder";
			case "sea": return "application/sea";
			case "sea": return "application/x-sea";
			case "set": return "application/set";
			case "sgm": return "text/sgml";
			case "sgm": return "text/x-sgml";
			case "sgml": return "text/sgml";
			case "sgml": return "text/x-sgml";
			case "sh": return "application/x-bsh";
			case "sh": return "application/x-sh";
			case "sh": return "application/x-shar";
			case "sh": return "text/x-script";
			case "shar": return "application/x-bsh";
			case "shar": return "application/x-shar";
			case "shtml": return "text/html";
			case "shtml": return "text/x-server-parsed-html";
			case "sid": return "audio/x-psid";
			case "sit": return "application/x-sit";
			case "sit": return "application/x-stuffit";
			case "skd": return "application/x-koan";
			case "skm": return "application/x-koan";
			case "skp": return "application/x-koan";
			case "skt": return "application/x-koan";
			case "sl": return "application/x-seelogo";
			case "smi": return "application/smil";
			case "smil": return "application/smil";
			case "snd": return "audio/basic";
			case "snd": return "audio/x-adpcm";
			case "sol": return "application/solids";
			case "spc": return "application/x-pkcs7-certificates";
			case "spc": return "text/x-speech";
			case "spl": return "application/futuresplash";
			case "spr": return "application/x-sprite";
			case "sprite": return "application/x-sprite";
			case "src": return "application/x-wais-source";
			case "ssi": return "text/x-server-parsed-html";
			case "ssm": return "application/streamingmedia";
			case "sst": return "application/vnd";
			case "step": return "application/step";
			case "stl": return "application/sla";
			case "stl": return "application/vnd";
			case "stl": return "application/x-navistyle";
			case "stp": return "application/step";
			case "sv4cpio": return "application/x-sv4cpio";
			case "sv4crc": return "application/x-sv4crc";
			case "svf": return "image/vnd";
			case "svf": return "image/x-dwg";
			case "svr": return "application/x-world";
			case "svr": return "x-world/x-svr";
			case "swf": return "application/x-shockwave-flash";
			case "t": return "application/x-troff";
			case "talk": return "text/x-speech";
			case "tar": return "application/x-tar";
			case "tbk": return "application/toolbook";
			case "tbk": return "application/x-tbook";
			case "tcl": return "application/x-tcl";
			case "tcl": return "text/x-script";
			case "tcsh": return "text/x-script";
			case "tex": return "application/x-tex";
			case "texi": return "application/x-texinfo";
			case "texinfo": return "application/x-texinfo";
			case "text": return "application/plain";
			case "text": return "text/plain";
			case "tgz": return "application/gnutar";
			case "tgz": return "application/x-compressed";
			case "tif": return "image/tiff";
			case "tif": return "image/x-tiff";
			case "tiff": return "image/tiff";
			case "tiff": return "image/x-tiff";
			case "tr": return "application/x-troff";
			case "tsi": return "audio/tsp-audio";
			case "tsp": return "application/dsptype";
			case "tsp": return "audio/tsplayer";
			case "tsv": return "text/tab-separated-values";
			case "turbot": return "image/florian";
			case "txt": return "text/plain";
			case "uil": return "text/x-uil";
			case "uni": return "text/uri-list";
			case "unis": return "text/uri-list";
			case "unv": return "application/i-deas";
			case "uri": return "text/uri-list";
			case "uris": return "text/uri-list";
			case "ustar": return "application/x-ustar";
			case "ustar": return "multipart/x-ustar";
			case "uu": return "application/octet-stream";
			case "uu": return "text/x-uuencode";
			case "uue": return "text/x-uuencode";
			case "vcd": return "application/x-cdlink";
			case "vcs": return "text/x-vcalendar";
			case "vda": return "application/vda";
			case "vdo": return "video/vdo";
			case "vew": return "application/groupwise";
			case "viv": return "video/vivo";
			case "viv": return "video/vnd";
			case "vivo": return "video/vivo";
			case "vivo": return "video/vnd";
			case "vmd": return "application/vocaltec-media-desc";
			case "vmf": return "application/vocaltec-media-file";
			case "voc": return "audio/voc";
			case "voc": return "audio/x-voc";
			case "vos": return "video/vosaic";
			case "vox": return "audio/voxware";
			case "vqe": return "audio/x-twinvq-plugin";
			case "vqf": return "audio/x-twinvq";
			case "vql": return "audio/x-twinvq-plugin";
			case "vrml": return "application/x-vrml";
			case "vrml": return "model/vrml";
			case "vrml": return "x-world/x-vrml";
			case "vrt": return "x-world/x-vrt";
			case "vsd": return "application/x-visio";
			case "vst": return "application/x-visio";
			case "vsw": return "application/x-visio";
			case "w60": return "application/wordperfect6";
			case "w61": return "application/wordperfect6";
			case "w6w": return "application/msword";
			case "wav": return "audio/wav";
			case "wav": return "audio/x-wav";
			case "wb1": return "application/x-qpro";
			case "wbmp": return "image/vnd";
			case "web": return "application/vnd";
			case "wiz": return "application/msword";
			case "wk1": return "application/x-123";
			case "wmf": return "windows/metafile";
			case "wml": return "text/vnd";
			case "wmlc": return "application/vnd";
			case "wmls": return "text/vnd";
			case "wmlsc": return "application/vnd";
			case "word": return "application/msword";
			case "wp": return "application/wordperfect";
			case "wp5": return "application/wordperfect";
			case "wp5": return "application/wordperfect6";
			case "wp6": return "application/wordperfect";
			case "wpd": return "application/wordperfect";
			case "wpd": return "application/x-wpwin";
			case "wq1": return "application/x-lotus";
			case "wri": return "application/mswrite";
			case "wri": return "application/x-wri";
			case "wrl": return "application/x-world";
			case "wrl": return "model/vrml";
			case "wrl": return "x-world/x-vrml";
			case "wrz": return "model/vrml";
			case "wrz": return "x-world/x-vrml";
			case "wsc": return "text/scriplet";
			case "wsrc": return "application/x-wais-source";
			case "wtk": return "application/x-wintalk";
			case "xbm": return "image/x-xbitmap";
			case "xbm": return "image/x-xbm";
			case "xbm": return "image/xbm";
			case "xdr": return "video/x-amt-demorun";
			case "xgz": return "xgl/drawing";
			case "xif": return "image/vnd";
			case "xl": return "application/excel";
			case "xla": return "application/excel";
			case "xla": return "application/x-excel";
			case "xla": return "application/x-msexcel";
			case "xlb": return "application/excel";
			case "xlb": return "application/vnd";
			case "xlb": return "application/x-excel";
			case "xlc": return "application/excel";
			case "xlc": return "application/vnd";
			case "xlc": return "application/x-excel";
			case "xld": return "application/excel";
			case "xld": return "application/x-excel";
			case "xlk": return "application/excel";
			case "xlk": return "application/x-excel";
			case "xll": return "application/excel";
			case "xll": return "application/vnd";
			case "xll": return "application/x-excel";
			case "xlm": return "application/excel";
			case "xlm": return "application/vnd";
			case "xlm": return "application/x-excel";
			case "xls": return "application/excel";
			case "xls": return "application/vnd";
			case "xls": return "application/x-excel";
			case "xls": return "application/x-msexcel";
			case "xlt": return "application/excel";
			case "xlt": return "application/x-excel";
			case "xlv": return "application/excel";
			case "xlv": return "application/x-excel";
			case "xlw": return "application/excel";
			case "xlw": return "application/vnd";
			case "xlw": return "application/x-excel";
			case "xlw": return "application/x-msexcel";
			case "xm": return "audio/xm";
			case "xml": return "application/xml";
			case "xml": return "text/xml";
			case "xmz": return "xgl/movie";
			case "xpix": return "application/x-vnd";
			case "xpm": return "image/x-xpixmap";
			case "xpm": return "image/xpm";
			case "x-png": return "image/png";
			case "xsr": return "video/x-amt-showrun";
			case "xwd": return "image/x-xwd";
			case "xwd": return "image/x-xwindowdump";
			case "xyz": return "chemical/x-pdb";
			case "z": return "application/x-compress";
			case "z": return "application/x-compressed";
			case "zip": return "application/x-compressed";
			case "zip": return "application/x-zip-compressed";
			case "zip": return "application/zip";
			case "zip": return "multipart/x-zip";
			case "zoo": return "application/octet-stream";
			case "zsh": return "text/x-script";
			default: return "application/$ext";
		}
	}
}

