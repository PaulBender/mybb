<?php
/**
 * MyBB 1.0
 * Copyright � 2005 MyBulletinBoard Group, All Rights Reserved
 *
 * Website: http://www.mybboard.com
 * License: http://www.mybboard.com/eula.html
 *
 * $Id$
 */

/*
options = array(
	allow_html
	allow_smilies
	allow_mycode
	nl2br
	is_archive
	filter_badwords
)
*/

class postParser
{
	/**
	 * Internal cache of MyCode.
	 *
	 * @var mixed
	 */
	var $mycode_cache = 0;

	/**
	 * Internal cache of smilies
	 *
	 * @var mixed
	 */
	var $smilies_cache = 0;

	/**
	 * Internal cache of badwords filters
	 *
	 * @var mixed
	 */
	var $badwords_cache = 0;

	/**
	 * Parses a message with the specified options.
	 *
	 * @param string The message to be parsed.
	 * @param array Array of yes/no options - allow_html,filter_badwords,allow_mycode,allow_smilies,nl2br.
	 * @return string The parsed message.
	 */
	function parse_message($message, $options=array())
	{
		global $plugins, $settings;
		$message = $this->fix_javascript($message);
		if($options['allow_html'] != "yes")
		{
			$message = $this->parse_html($message);
		}

		if($options['filter_badwords'] != "no")
		{
			$message = $this->parse_badwords($message);
		}

		if($options['allow_mycode'] != "no")
		{
			// First we split up the contents of code and php tags to ensure they're not parsed.
			preg_match_all("#\[(code|php)\](.*?)\[/\\1\]#si", $message, $code_matches, PREG_SET_ORDER);
			$message = preg_replace("#\[(code|php)\](.*?)\[/\\1\]#si", "<mybb-code>", $message);
		}

		if($options['allow_smilies'] != "no")
		{
			if($options['is_archive'] == "yes")
			{
				$message = $this->parse_smilies($message, $settings['bburl']);
			}
			else
			{
				$message = $this->parse_smilies($message);
			}
		}

		if($options['allow_mycode'] != "no")
		{
			$message = $this->parse_mycode($message, $options);
		}

		// Run plugin hooks
		$message = $plugins->run_hooks("parse_message", $message);

		if($options['allow_mycode'] != "no")
		{
			// Now that we're done, if we split up any code tags, parse them and glue it all back together
			if(count($code_matches) > 0)
			{
				foreach($code_matches as $text)
				{
					if(strtolower($text[1]) == "code")
					{
						$code = $this->mycode_parse_code($text[2]);
					}
					elseif(strtolower($text[1]) == "php")
					{
						$code = $this->mycode_parse_php($text[2]);
					}
					$message = preg_replace("#<mybb-code>#", $code, $message, 1);
				}
			}
		}

		if($options['nl2br'] != "no")
		{
			$message = nl2br($message);
		}
		return $message;
	}

	/**
	 * Converts HTML in a message to their specific entities whilst allowing unicode characters.
	 *
	 * @param string The message to be parsed.
	 * @return string The formatted message.
	 */
	function parse_html($message)
	{
		$message = preg_replace("#&(?!\#[0-9]+;)#si", "&amp;", $message); // fix & but allow unicode
		$message = str_replace("<","&lt;",$message);
		$message = str_replace(">","&gt;",$message);
		return $message;
	}

	/**
	 * Generates a cache of MyCode, both standard and custom.
	 *
	 * @access private
	 */
	function cache_mycode()
	{
		global $cache;
		$this->mycode_cache = array();

		$standard_mycode['b']['regex'] = "#\[b\](.*?)\[/b\]#si";
		$standard_mycode['b']['replacement'] = "<strong>$1</strong>";

		$standard_mycode['u']['regex'] = "#\[u\](.*?)\[/u\]#si";
		$standard_mycode['u']['replacement'] = "<u>$1</u>";

		$standard_mycode['i']['regex'] = "#\[i\](.*?)\[/i\]#si";
		$standard_mycode['i']['replacement'] = "<em>$1</em>";

		$standard_mycode['s']['regex'] = "#\[s\](.*?)\[/s\]#si";
		$standard_mycode['s']['replacement'] = "<del>$1</del>";

		$standard_mycode['copy']['regex'] = "#\(c\)#i";
		$standard_mycode['copy']['replacement'] = "&copy;";

		$standard_mycode['tm']['regex'] = "#\(tm\)#i";
		$standard_mycode['tm']['replacement'] = "&#153;";

		$standard_mycode['reg']['regex'] = "#\(r\)#i";
		$standard_mycode['reg']['replacement'] = "&reg;";

		$standard_mycode['url_simple']['regex'] = "#\[url\]([a-z]+?://)([^\r\n\"\[<]+?)\[/url\]#sei";
		$standard_mycode['url_simple']['replacement'] = "\$this->mycode_parse_url(\"$1$2\")";

		$standard_mycode['url_simple2']['regex'] = "#\[url\]([^\r\n\"\[<]+?)\[/url\]#ei";
		$standard_mycode['url_simple2']['replacement'] = "\$this->mycode_parse_url(\"$1\")";

		$standard_mycode['url_complex']['regex'] = "#\[url=([a-z]+?://)([^\r\n\"\[<]+?)\](.+?)\[/url\]#esi";
		$standard_mycode['url_complex']['replacement'] = "\$this->mycode_parse_url(\"$1$2\", \"$3\")";

		$standard_mycode['url_complex2']['regex'] = "#\[url=([^\r\n\"\[<]+?)\](.+?)\[/ur\]#esi";
		$standard_mycode['url_complex2']['replacement'] = "\$this->mycode_parse_url(\"$1\", \"$2\")";

		$standard_mycode['email_simple']['regex'] = "#\[email\](.*?)\[/email\]#ei";
		$standard_mycode['email_simple']['replacement'] = "\$this->mycode_parse_email(\"$1\")";

		$standard_mycode['email_complex']['regex'] = "#\[email=(.*?)\](.*?)\[/email\]#ei";
		$standard_mycode['email_complex']['replacement'] = "\$this->mycode_parse_email(\"$1\", \"$2\")";

		$standard_mycode['color']['regex'] = "#\[color=([a-zA-Z]*|\#?[0-9a-fA-F]{6})](.*?)\[/color\]#si";
		$standard_mycode['color']['replacement'] = "<span style=\"color: $1;\">$2</span>";

		$standard_mycode['size']['regex'] = "#\[size=(small|medium|large|x-large|xx-large)\](.*?)\[/size\]#si";
		$standard_mycode['size']['replacement'] = "<span style=\"font-size: $1;\">$2</span>";

		$standard_mycode['size_int']['regex'] = "#\[size=([0-9\+\-]+?)\](.*?)\[/size\]#si";
		$standard_mycode['size_int']['replacement'] = "<font size=\"$1\">$2</font>";

		$standard_mycode['font']['regex'] = "#\[font=([a-z ]+?)\](.+?)\[/font\]#si";
		$standard_mycode['font']['replacement'] = "<span style=\"font-family: $1;\">$2</span>";

		$standard_mycode['align']['regex'] = "#\[align=(left|center|right|justify)\](.*?)\[/align\]#si";
		$standard_mycode['align']['replacement'] = "<p style=\"text-align: $1;\">$2</p>";

		$standard_mycode['hr']['regex'] = "#\[hr\]#si";
		$standard_mycode['hr']['replacement'] = "<hr />";

		$custom_mycode = $cache->read("mycode");

		if(is_array($custom_mycode))
		{
			$mycode = array_merge($standard_mycode, $custom_mycode);
		}
		else
		{
			$mycode = $standard_mycode;
		}

		foreach($mycode as $code)
		{
			$this->mycode_cache['find'][] = $code['regex'];
			$this->mycode_cache['replacement'][] = $code['replacement'];
		}
	}

	/**
	 * Parses MyCode tags in a specific message with the specified options.
	 *
	 * @param string The message to be parsed.
	 * @param array Array of options in yes/no format. Options are allow_imgcode.
	 * @return string The parsed message.
	 */
	function parse_mycode($message, $options=array())
	{
		if($this->mycode_cache == 0)
		{
			$this->cache_mycode();
		}

		// Parse quotes first
		$message = $this->mycode_parse_quotes($message);

		// Replace the rest
		$message = preg_replace($this->mycode_cache['find'], $this->mycode_cache['replacement'], $message);

		// special code requiring special attention
		while(preg_match("#\[list\](.*?)\[/list\]#esi", $message))
		{
			$message = preg_replace("#\[list\](.*?)\[/list\]#esi", "\$this->mycode_parse_list('$1')", $message);
		}

		while(preg_match("#\[list=(a|A|i|I|1)\](.*?)\[/list\]#esi", $message))
		{
			$message = preg_replace("#\[list=(a|A|i|I|1)\](.*?)\[/list\]#esi", "\$this->mycode_parse_list('$2', '$1')", $message);
		}

		if($options['allow_imgcode'] != "no")
		{
			$message = preg_replace("#\[img\]([a-z]+?://){1}(.+?)\[/img\]#i", "<img src=\"$1$2\" border=\"0\" alt=\"\" />", $message);
			$message = preg_replace("#\[img=([0-9]{1,3})x([0-9]{1,3})\]([a-z]+?://){1}(.+?)\[/img\]#i", "<img src=\"$3$4\" style=\"border: 0; width: $1; height: $2;\" alt=\"\" />", $message);
		}

		$message = $this->mycode_auto_url($message);

		return $message;
	}

	/**
	 * Generates a cache of smilies
	 *
	 * @access private
	 */
	function cache_smilies()
	{
		global $cache;
		$this->smilies_cache = array();
		$this->smilies_cache = $cache->read("smilies");
	}

	/**
	 * Parses smilie code in the specified message.
	 *
	 * @param string The message being parsed.
	 * @param string Base URL for the image tags created by smilies.
	 * @return string The parsed message.
	 */
	function parse_smilies($message, $url="")
	{
		if($this->smilies_cache == 0)
		{
			$this->cache_smilies();
		}

		if($url != "")
		{
			if(substr($url, strlen($url) -1) != "/")
			{
				$url = $url."/";
			}
		}
		if(is_array($this->smilies_cache))
		{
			reset($this->smilies_cache);
			foreach($this->smilies_cache as $sid => $smilie)
			{
				$message = str_replace($smilie['find'], "<img src=\"".$url.$smilie['image']."\" style=\"vertical-align: middle;\" border=\"0\" alt=\"".$smilie['name']."\" />", $message);
			}
		}
		return $message;
	}

	function parse_mecode()
	{
		// Hi!
	}

	/**
	 * Generates a cache of badwords filters.
	 *
	 * @access private
	 */
	function cache_badwords()
	{
		global $cache;
		$this->badwords_cache = array();
		$this->badwords_cache = $cache->read("badwords");
	}

	/**
	 * Parses a list of filtered/badwords in the specified message.
	 *
	 * @param string The message to be parsed.
	 * @param array Array of parser options in yes/no format.
	 * @return string The parsed message.
	 */
	function parse_badwords($message, $options=array())
	{
		if($this->badwords_cache == 0)
		{
			$this->cache_badwords();
		}
		if(is_array($this->badwords_cache))
		{
			reset($this->badwords_cache);
			foreach($this->badwords_cache as $bid => $badword)
			{
				if(!$badword['replacement']) $badword['replacement'] = "*****";
				$badword['badword'] = preg_quote($badword['badword']);
				$message = preg_replace("#".$badword['badword']."#i", $badword['replacement'], $message);
			}
		}
		if($options['strip_tags'] == "yes")
		{
			$message = strip_tags($message);
		}
		return $message;
	}

	/**
	 * Attempts to move any javascript references in the specified message.
	 *
	 * @param string The message to be parsed.
	 * @return string The parsed message.
	 */
	function fix_javascript($message)
	{
		$message = preg_replace("#(java)(script:)#i", "$1 $2", $message);
		$js_array = array(
			"#(a)(lert)#ie", 
			"#(o)(nmouseover)#ie", 
			"#(o)(nmouseout)#ie",
			"#(o)(nmousedown)#ie",
			"#(o)(nmousemove)#ie", 
			"#(o)(nmouseup)#ie",  
			"#(o)(nclick)#ie",
			"#(o)(ndblclick)#ie", 
			"#(o)(nload)#ie", 
			"#(o)(nsubmit)#ie", 
			"#(o)(nblur)#ie", 
			"#(o)(nchange)#ie",
			"#(o)(nfocus)#ie",
			"#(o)(nselect)#ie",
			"#(o)(nunload)#ie",
			"#(o)(nkeypress)#ie"
			);
		$message = preg_replace($js_array, "'&#'.ord($1).';$2'", $message);
		return $message;
	}

	function mycode_parse_quotes($message)
	{
		global $lang;

		// user sanity check
		$pattern = array("#\[quote=(?:&quot;|\"|')?(.*?)[\"']?(?:&quot;|\"|')?\](.*?)\[\/quote\]#si",
						 "#\[quote\](.*?)\[\/quote\]#si");

		$replace = array("<div class=\"quote_header\">$1 $lang->wrote</div><div class=\"quote_body\">$2</div>",
						 "<div class=\"quote_header\">$lang->quote</div><div class=\"quote_body\">$1</div>\n");

		while (preg_match($pattern[0], $message) or preg_match($pattern[1], $message))
		{
			$message = preg_replace($pattern, $replace, $message);
		}
		$message = str_replace("<div class=\"quote_body\"><br />", "<div class=\"quote_body\">", $message);
		$message = str_replace("<br /></div>", "</div>", $message);
		return $message;

	}

	function mycode_parse_code($code)
	{
		global $lang;
		return "<div class=\"code_header\">".$lang->code."</div><div class=\"code_body\"><pre><code>".$code."</code></pre></div>";
	}

	function mycode_parse_php($str)
	{
		global $lang;

		$str = str_replace('&lt;', '<', $str);
		$str = str_replace('&gt;', '>', $str);
		$str = str_replace('&amp;', '&', $str);
		$str = str_replace("\n", '', $str);
		$original = $str;

		if(preg_match("/\A[\s]*\<\?/", $str) === 0)
		{
			$str = "<?php\n".$str;
		}

		if(preg_match("/\A[\s]*\>\?/", strrev($str)) === 0)
		{
			$str = $str."\n?>";
		}

		if(substr(phpversion(), 0, 1) >= 4)
		{
			ob_start();
			@highlight_string($str);
			$code = ob_get_contents();
			ob_end_clean();
		}
		else
		{
			$code = $str;
		}

		if(preg_match("/\A[\s]*\<\?/", $original) === 0)
		{
			$code = substr_replace($code, "", strpos($code, "&lt;?php"), strlen("&lt;?php"));
			$code = strrev(substr_replace(strrev($code), "", strpos(strrev($code), strrev("?&gt;")), strlen("?&gt;")));
			$code = str_replace('<br />', '', $code);
		}

		// Get rid of other useless code and linebreaks
		$code = str_replace("<code><font color=\"#000000\">\n", '', $code);
		$code = str_replace('<font color="#0000CC"></font>', '', $code);
		$code = str_replace("</font>\n</code>", '', $code);

		// Send back the code all nice and pretty
		return "<div class=\"code_header\">$lang->php_code</div><div class=\"code_body\"><pre><code>".$code."</code></pre></div>";
	}

	function mycode_parse_url($url, $name="")
	{
		$fullurl = $url;
		if(strpos($url, "www.") === 0)
		{
			$fullurl = "http://".$fullurl;
		}
		if(strpos($url, "ftp.") === 0)
		{
			$fullurl = "ftp://".$fullurl;
		}
		if(strpos($fullurl, "://") === false)
		{
			$fullurl = "http://".$fullurl;
		}
		if(!$name)
		{
			$name = $url;
		}
		$name = stripslashes($name);
		$url = stripslashes($url);
		$fullurl = stripslashes($fullurl);
		if($name == $url)
		{
			if(strlen($url) > 55)
			{
				$name = substr($url, 0, 40)."...".substr($url, -10);
			}
		}
		$link = "<a href=\"$fullurl\" target=\"_blank\">$name</a>";
		return $link;
	}

	function mycode_parse_email($email, $name="")
	{
		if(!$name)
		{
			$name = $email;
		}
		if(preg_match("/^(.+)@[a-zA-Z0-9-]+\.[a-zA-Z0-9.-]+$/si", $email))
		{
			return "<a href=\"mailto:$email\">".$name."</a>";
		}
	}

	function mycode_auto_url($message)
	{
		$message = " ".$message;
		$message = preg_replace("#([\s\(\)])(https?|ftp|news){1}://([\w\-]+\.([\w\-]+\.)*[\w]+(:[0-9]+)?(/[^\"\s\(\)<\[]*)?)#ie", "\"$1\".\$this->mycode_parse_url(\"$2://$3\")", $message);
		$message = preg_replace("#([\s\(\)])(www|ftp)\.(([\w\-]+\.)*[\w]+(:[0-9]+)?(/[^\"\s\(\)<\[]*)?)#ie", "\"$1\".\$this->mycode_parse_url(\"$2.$3\", \"$2.$3\")", $message);
		$message = substr($message, 1);
		return $message;
	}

	function mycode_parse_list($message, $type="")
	{
		$message = str_replace('\"', '"', $message);
		$message = preg_replace("#\[\*\]#", "</li><li>", $message);
		$message .= "</li>";

		if($type)
		{
			$list = "<ol type=\"$type\">$message</ol>";
		}
		else
		{
			$list = "<ul>$message</ul>";
		}
		$list = preg_replace("#<(ol type=\"$type\"|ul)>\s*</li>#", "<$1>", $list);
		return $list;
	}

	function strip_mycode($message)
	{
		$options['allow_html'] = "no";
		$options['allow_smilies'] = "no";
		$options['allow_mycode'] = "yes";
		$options['nl2br'] = "no";
		$options['filter_badwords'] = "no";
		$message = $this->parse_message($message, $options);
		$message = strip_tags($message);
		return $message;
	}
}
?>