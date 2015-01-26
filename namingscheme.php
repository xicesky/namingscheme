#!/usr/bin/php5
<?php

    $re_video_suffix = "#\\.(avi|mp4|mkv)$#";

    $re_spaces = "[. _\t\r\n]";
    $re_notspaces = "[^. _\t\r\n]";
    $re_title_specials = "[,'`´()&+-]";

    $re_series_title = array(
        array("#^((.*)$re_spaces)(S\\d+|\\d+x)#i", array("#^(.*$re_notspaces)$re_spaces#i", "$1") ),
        );
        
    $re_episode_number = array(
        array("#^(\\d+x\\d+)#i", array("#^(\\d+)\\D(\\d+)#", "$1/$2")),
        array("#^(S\\d+$re_spaces?E\\d+)#i", array("#^S(\\d+)$re_spaces?E(\\d+)#i", "$1/$2")),
        array("#^(\\d+x\\d+-\\d+)#i", array("#^(\\d+)x(\\d+-\\d+)#i", "$1/$2")), 
        # Extras (Bonus episodes)
        array("#^(\\d+xXX$re_spaces*Extra\\w+)#i", array("#^(\\d+)\\DXX$re_spaces*(Extra\\w+)#", "$1/ZZ/$2")),
        array("#^(S(\\d+)$re_spaces*Extra\\w+)#i", array("#^S(\\d+)$re_spaces*(Extra\\w+)#i", "$1/ZZ/$2")),
        );
    
    $re_episode_title_part = array(
        array("#^((\w|$re_title_specials)+)#i", 1),
        );
        
    $re_tags = array(
        array("#^(PROPER|REPACK|iNTERNAL)#", 1),    # Common simple tags
        array("#^((HDTV|DVD|XVID|X264|720p|WEBRIP)[^.]*)#i", 1),   # Common tag prefixes
        array("#^((sub\\W(en|de|fr)))#i", array('#\\.#', ' ')),   # Sub tag
        array("#^(\\[([^\\]]+)\\])#", 2),   # Tag in brackets = good
        );
    
    $files = glob("*");
    
    # Error handling via exceptions preferred #########################################################################
    
    class RegExErrorException extends ErrorException {
        public $info = NULL;
        public function __construct(
            $message, $code, $severity,
            $filename, $lineno, $previous = NULL) {
            parent::__construct($message, $code, $severity, $filename, $lineno, $previous);
            $this->info = array();
        }
        public function __toString() {
            $s = "RegExErrorException: " . parent::__toString() . "\n";
            $s .= "getInfo(): " . $this->getInfo(true);
            return $s;
        }
        public function getInfo($asString = true) {
            return $asString ? var_export($this->info, true) : $this->info;
        }
    }
    
    function exception_error_handler($errno, $errstr, $errfile, $errline ) {
        if (($errno == E_WARNING) && (substr($errstr, 0, 14) == "preg_match(): "))
            throw new RegExErrorException($errstr, $errno, 0, $errfile, $errline);
        #echo "exception_error_handler(): $errno\n";
        throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
    }
    set_error_handler("exception_error_handler");

    
    # Try a number of REs in order, report which one worked ###########################################################
    function try_res($res, $str) {
        foreach ($res as $re) {
            try {
                if (preg_match($re[0], $str, $m))
                    return array($re, $m);
            } catch (RegExErrorException $e) {
                $e->info[] = $re;
                throw $e;
            }
        }
        return false;
    }
    
    # Helper function for recognition
    $recognition_errors = 0; // TODO: array();
    function recognize($res, &$string, $critical, $recogname = "???", $recoginfo = array()) {
        global $recognition_errors, $re_spaces;
        
        # Find a matching RE
        $r = try_res($res, $string);
        if ($r === false) {     # Not recognized
            if (!$critical)
                return false;
            echo "Could not recognize $recogname at: $string\n";
            foreach ($recoginfo as $i) echo "    $i\n";
            $recognition_errors++;
            return false;
        }
        
        list($re, $m) = $r;
        assert(is_array($re));
        
        # Postprocessing:
        
        # The first match is the part that was recognized
        $matched = $m[1];
        
        # Fetch the postprocessing part of the RE
        $postproc = $re[1];

        # No postprocessing, just return the right part of the match
        if (is_int($postproc)) {
            # Remove the matched part from the string
            $string = substr($string, strlen($matched));
            $string = preg_replace("#^$re_spaces*#", "", $string); # (and spaces)
            return $m[$postproc];
        }
        
        # Postprocess using a function (very flexible)
        if (is_callable($postproc)) {
            list($nmatched, $result) = $postproc($re, $m, $string, $recoginfo);
            # Remove the matched part from the string
            $string = substr($string, $nmatched);
            $string = preg_replace("#^$re_spaces*#", "", $string); # (and spaces)
            return $result;
        }
        
        # Postprocess using preg_replace
        if (is_array($postproc)) {
            # Remove the matched part from the string
            $string = substr($string, strlen($matched));
            $string = preg_replace("#^$re_spaces*#", "", $string); # (and spaces)
            $result = preg_replace($postproc[0], $postproc[1], $matched);
            return $result;
        }

        # Unused
        /*
        if (is_string($postproc) && preg_match($postproc, $m[1], $mp))
            return $mp;
        /**/
        
        # Debug
        echo "Invalid RE postproc:\n";
        echo "$re = "; var_export($re);
        echo "$str = "; var_export($str);
        echo "$m = "; var_export($m);
        die();
    }
    
    # This array stores recognized files and their details
    $matches = array();
    
    foreach ($files as $fname) {
        $current = array();
        $current["file"] = $fname;
    
        # Try to recognize the file suffix (file type) ################################################################
        
        if (!preg_match($re_video_suffix, $fname, $m))
            continue;
        $suffix = $m[0];
        $prefix = substr($fname, 0, -strlen($suffix));
        // Hack
        $prefix = html_entity_decode($prefix, ENT_NOQUOTES);

        # Try to recognize the series title ###########################################################################
        
        $series_title = recognize($re_series_title, $prefix, true, "series title", $current);
        if ($series_title === false) continue;
        $series_title = preg_replace("#$re_spaces+#", ".", $series_title);
        
        # Try to recognize the episode number #########################################################################
        
        $epnr = recognize($re_episode_number, $prefix, true, "episode number", $current);
        if ($epnr === false) continue;
        $epnrs = explode("/", $epnr);
        switch (count($epnrs)) {
            case 2: $epnr = sprintf("%dx%02d", (int)$epnrs[0], (int)$epnrs[1]); break;
            case 3: $epnr = sprintf("%dxXX.%s", (int)$epnrs[0], $epnrs[2]); break;
            default: die("MOOOOOO"); break;
        }
        #$epnr = sprintf("%dx%02d", (int)$epnrs[1], (int)$epnrs[2]);
        
        # Recognize tags and the episode title ########################################################################
        
        $tags = array();
        
        # Leading tags
        for (;;) {
            $tag = recognize($re_tags, $prefix, false); // No reason to break things if not recognized
            if ($tag === false) break;
            $tags[] = $tag;
        }
        
        # Episode title ends at next tag
        $episode_title = "";
        for (;;) {
            $tag = recognize($re_tags, $prefix, false);
            if ($tag !== false) {
                $tags[] = $tag;
                break;
            }
            if (strlen($prefix) == 0) break;
            $tp = recognize($re_episode_title_part, $prefix, true, "episode title", $current);
            if ($tp === false) continue 2;
            $episode_title = $episode_title . "." . $tp;
        }
        $episode_title = substr($episode_title, 1); // First dot is wrong
        
        # Recognize more tags #########################################################################################
        
        for (;;) {
            $tag = recognize($re_tags, $prefix, false); // No reason to break things if not recognized
            if ($tag === false) break;
            $tags[] = $tag;
        }
        
        # Recognition done, report ####################################################################################
        
        if (strlen($prefix) > 0) {
            echo "No full recognition for:\n";
            echo "    $fname\n";
            echo "    Rest: $prefix\n";
            echo "\n";
            $recognition_errors++;
            continue;
        }
        
        /*
        echo "-------------------------------------------------------------------------------\n";
        echo "$fname\n";
        echo "    Series:   $series_title\n";
        echo "    Number:   $epnr\n";
        echo "    Episode:  $episode_title\n";
        echo "    Tags:\n";
        print_r($tags);
        echo "    Rest:     $prefix\n";
        echo "    Suffix:   $suffix\n";
        echo "\n";
        */
        
        //echo "$series_title $epnr $episode_title\n";
        $matches[$fname] = array(
            "file" => $fname,
            "epnr" => $epnr,
            "series_title" => $series_title,
            "episode_title" => $episode_title,
            "tags" => $tags,
            "suffix" => $suffix,
        );
    }
    
    #die(); # happy
    if ($recognition_errors > 0) {
        echo "-------------------------------------------------------------------------------\n";
        echo "Continue?\n";
        fgets(STDIN);
        echo "-------------------------------------------------------------------------------\n";
    }
    
    // Normalize series title
    $title_count = array();
    foreach ($matches as $m) {
        $t = $m["series_title"];
        if (!isset($title_count[$t]))
            $title_count[$t] = 0;
        $title_count[$t]++;
    }
    $titles = array_keys($title_count);
    
    if (count($titles) > 1) {
        echo "Pick a title:\n";
        foreach ($titles as $k => $t) {
            echo sprintf("%2d: %s\n", $k+1, $t);
        }
        echo "Number? ";
        $n = (int)trim(fgets(STDIN)) - 1;
        $title = $titles[$n];
    } else
        $title = $titles[0];
    echo "Common title: $title\n";
    foreach ($matches as $k => $m)
        $matches[$k]["series_title"] = $title;
    
    //print_r($matches);
    
    echo "-------------------------------------------------------------------------------\n";
    echo "Will rename to the following filenames:\n";
    
    foreach ($matches as $mk => $m) {
        $name = array();
        $name[] = $m["series_title"];
        $name[] = $m["epnr"];
        
        if (strlen($m["episode_title"]) > 0)
            $name[] = $m["episode_title"];
        
        $tags = $m["tags"];
        foreach ($tags as $k => $t) {
            if (!preg_match("#^\\[.*\\]$#", $t))
                $tags[$k] = "[".$t."]";
        }
        $name = array_merge($name, $tags);
        $name = implode(".", $name) . $m["suffix"];

        if ($name == $m["file"])
            continue;
        $matches[$mk]["newname"] = $name;
        echo "{$m["file"]}\n";
        echo "         -> $name\n";

        if (file_exists($name))
            echo "        --- FILE ALREADY EXISTS!\n";
    }
    
    //print_r($matches);
    
    echo "\n";
    echo "Ok to rename?\n";
    if (!preg_match("#^y(es)?$#i", trim(fgets(STDIN)))) { echo "meh.\n"; die(); }
    
    foreach ($matches as $m) {
        if (!isset($m["newname"]))
            continue;
        $from = $m["file"];
        $to   = $m["newname"];
        
        if (file_exists($to)) {
            echo "Cannot rename: $from -> $to\n";
            echo "    (file exists)\n";
            continue;
        }
        
        echo "$from -> $to\n";
        rename($from, $to);
    }
    
?>
