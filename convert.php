<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$arguments = arguments($argv);

require 'vendor/autoload.php';



// Load arguments passed from CLI 

if(empty($arguments['filename'])) {
    echo "No input file specified. Use --filename=mediawiki.xml" . PHP_EOL . PHP_EOL; 
    exit;
}

if(!empty($arguments['output'])) {
    $output_path = $arguments['output'];
        
    if(!file_exists($output_path)) {
        echo "Creating output directory $output_path" . PHP_EOL . PHP_EOL;
        mkdir($output_path,0777,true);
    }

} else {
    $output_path = '';
}

if(!empty($arguments['format'])) {
    $format = $arguments['format'];
} else {
    $format = 'gfm';
}


if(!empty($arguments['fm']) OR (empty($arguments['fm']) && $format == 'gfm')) {
    $add_meta = true;
} else {
    $add_meta = false;
}




// Load XML file
$file = file_get_contents($arguments['filename']);

$xml = str_replace('xmlns=', 'ns=', $file); //$string is a string that contains xml... 

$xml = new SimpleXMLElement($xml);


$result = $xml->xpath('page');
$count = 0;
$directory_list = array();

// Iterate through XML
while(list( , $node) = each($result)) {
    
    $title = $node->xpath('title');
    $title = $title[0];
    $url = str_replace(['/', ' ', '"', '\'', ':', '`', '\`', '!', '(', ')', '@', ',', ';', '$', '\\', '.', '+', '&', '='], ['-', '-', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''], $title);
	if (substr($url, 0, 1) == '-') {
		$url = substr($url , 1);
	}
	$url = strtolower($url);
    if($slash = strpos($url, '/')){
        $title = str_replace('/', ' ', $title);
        $directory = substr($url, 0, $slash);
        $filename = substr($url, $slash+1);
        $directory_list[$directory] = true;
    } else {
        $directory = '';
        $filename = $url;
    }
	if (preg_match('/^Category:/', $title) || preg_match('/^Category talk:/', $title)) {
		echo "Skipping Page $title\n";
		continue;
	}

    $text = $node->xpath('revision/text');
    $text = $text[0];
    $text = html_entity_decode($text); // decode inline html
	$tags = ['intwiki'];
	if (preg_match_all('/\[\[Category:([^\]]+)\]\]/', $text, $matches)) {
		foreach ($matches[1] as $match) {
			$tags[] = $match;
			$text = str_replace('[[Category:'.$match.']]', '', $text);
		}
	}
    
    $text = preg_replace_callback('/\[\[(.+?)\]\]/', "new_link", $text); // adds leading slash to links, "absolute-path reference"
    // prepare to append page title frontmatter to text
    if ($add_meta) {    
        $frontmatter = "---\n";
        $frontmatter .= "title: $title\n";
        $frontmatter .= "permalink: /$url/\n";
        $frontmatter .= "tags: ".implode(', ', $tags)."\n";
        $frontmatter .= "---\n\n";
    }

    $pandoc = new Pandoc\Pandoc();
    $options = array(
        "from"  => "mediawiki",
        "to"    => $format
    );
	try {
	    $text = $pandoc->runWith($text, $options);
	} catch (\Pandoc\PandocException $e) {
		echo "Failed Converting in Pandoc With Error: ".$e->getMessage().PHP_EOL;
		continue;
	}

    $text = str_replace('\_', '_', $text);

    if ($add_meta) {
        $text = $frontmatter . $text;
    }

    if (substr($output_path, -1) != '/') $output_path = $output_path . '/';

    $directory = $output_path . $directory;

    // create directory if necessary
    if(!empty($directory)) {
        if(!file_exists($directory)) {
            mkdir($directory,0777,true);
        }

        $directory = $directory . '/';
    }

    // create file
	@mkdir(dirname(normalizePath($directory . $filename . '.md')), 0777, true);
    $file = fopen(normalizePath($directory . $filename . '.md'), 'w');
    fwrite($file, $text);
    fclose($file);

    $count++;

}


// Rename and move files with the same name as directories
if (!empty($directory_list) && !empty($arguments['indexes'])) {

    $directory_list = array_keys($directory_list);

    foreach ($directory_list as $directory_name) {

        if(file_exists($output_path . $directory_name . '.md')) {
            rename($output_path . $directory_name . '.md', $output_path . $directory_name . '/index.md');
        }
    }

}

if ($count > 0) {
    echo "$count files converted" . PHP_EOL . PHP_EOL;
}


function arguments($argv) {
    $_ARG = array();
    foreach ($argv as $arg) {
      if (preg_match('/--([^=]+)=(.*)/',$arg,$reg)) {
        $_ARG[$reg[1]] = $reg[2];
      } elseif(preg_match('/-([a-zA-Z0-9])/',$arg,$reg)) {
            $_ARG[$reg[1]] = 'true';
        }
  
    }
  return $_ARG;
}


function new_link($matches){
    if(strpos($matches[1], '|') != true) {
        $new_link = str_replace(' ', '_', $matches[1]);
        return "[[/$new_link|${matches[1]}]]";
    } else {

        $link = trim(substr($matches[1], 0, strpos($matches[1], '|')));
        $link = '/' . str_replace(' ', '_', $link);

        $link_text = trim(substr($matches[1], strpos($matches[1], '|')+1));

        return "[[$link|$link_text]]";
    }
}


// Borrowed from http://php.net/manual/en/function.realpath.php
function normalizePath($path)
{
    $parts = array();                         // Array to build a new path from the good parts
    $path = str_replace('\\', '/', $path);    // Replace backslashes with forwardslashes
    $path = preg_replace('/\/+/', '/', $path);// Combine multiple slashes into a single slash
    $segments = explode('/', $path);          // Collect path segments
    $test = '';                               // Initialize testing variable
    foreach($segments as $segment)
    {
        if($segment != '.')
        {
            $test = array_pop($parts);
            if(is_null($test))
                $parts[] = $segment;
            else if($segment == '..')
            {
                if($test == '..')
                    $parts[] = $test;
                if($test == '..' || $test == '')
                    $parts[] = $segment;
            }
            else
            {
                $parts[] = $test;
                $parts[] = $segment;
            }
        }
    }
    return implode('/', $parts);
}


