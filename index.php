<?php
$db_path = './';
$db_filename = 'opds.db';
$search_enable = true;
$convert_enable = false;
$lib_path = '/archive/ARCHIVE/[BOOKS]/[TEXT]/librusEC/';
$inpx_filename = 'rp4-fb2-librusec-russian-2015-08-18.inpx';
$inpx_input = $lib_path . $inpx_filename;
$tmp_path = '/tmp/opds/';
$items_per_page = 50;
$default_encoding='Windows-1251';
//$authors_letters_level = array("A", "B", "C", "D", "E", "F", "G", "I", "J", "K", "L", "M", "N", "O");

// TODO:
// - cleanup temporary directory after convertation
// - get rid of fixed filename for .inpx
// ~ keep dates on convertation
// - extract convertation script to separate file (?)
// - support alphabet level for authors
// - support search by authors and title simuously
// - archive files on download
// ~ fill authors while browsing author's feed

setlocale(LC_ALL, "UTF-8");

class MyDB extends SQLite3
{
    function __construct($db_filename)
    {
		$this->open($db_filename);
    }

    function create()
    {
	$sql =<<<EOF
	    CREATE TABLE IF NOT EXISTS 'Authors' (
		_id INTEGER PRIMARY KEY AUTOINCREMENT,
		author CHAR(256) NOT NULL,
		UNIQUE(author));
	    CREATE TABLE IF NOT EXISTS 'Genres' (
		_id INTEGER PRIMARY KEY AUTOINCREMENT,
		genre CHAR(256) NOT NULL,
		UNIQUE(genre));
	    CREATE TABLE IF NOT EXISTS 'Titles' (
		_id INTEGER PRIMARY KEY AUTOINCREMENT,
		title CHAR(256) NOT NULL,
		UNIQUE(title));
	    CREATE TABLE IF NOT EXISTS 'Files' (
		_id INTEGER PRIMARY KEY AUTOINCREMENT,
		file_name CHAR(256) NOT NULL,
		size INTEGER NOT NULL,
		date_add DATETIME DEFAULT CURRENT_TIMESTAMP,
		file_ext CHAR(8),
		lang CHAR(8),
		title_id INTEGER REFERENCES Titles(_id) ON DELETE CASCADE,
		UNIQUE(file_name, file_ext));
	    CREATE TABLE IF NOT EXISTS 'FilesByGenres' (
		file_id INTEGER REFERENCES Files(_id) ON DELETE CASCADE,
		genre_id INTEGER REFERENCES Genres(_id) ON DELETE CASCADE,
		PRIMARY KEY (file_id, genre_id));
	    CREATE TABLE IF NOT EXISTS 'FilesByAuthors' (
		file_id INTEGER REFERENCES Files(_id) ON DELETE CASCADE,
		author_id INTEGER REFERENCES Authors(_id) ON DELETE CASCADE,
		PRIMARY KEY (file_id, author_id));
EOF;
	$ret = $this->exec($sql);
	if (!$ret)
	{
	    echo $this->lastErrorMsg();
	}
    }

    function addAuthor($author)
    {
		$stm_sel = $this->prepare('SELECT _id FROM "Authors" WHERE author=?;');
		$stm_sel->bindParam(1, $author, SQLITE3_TEXT);
		$res = $stm_sel->execute();
		while ($row = $res->fetchArray())
		{
	    	return $row['_id'];
		}

		$stm_ins = $this->prepare('INSERT INTO "Authors" (author) VALUES (?)');
		$stm_ins->bindParam(1, $author, SQLITE3_TEXT);
		if ($stm_ins->execute())
	    	return $this->addAuthor($author);
		return 0;
    }

    function addGenre($genre)
    {
		$stm_sel = $this->prepare('SELECT _id FROM "Genres" WHERE genre=?;');
		$stm_sel->bindParam(1, $genre, SQLITE3_TEXT);
		$res = $stm_sel->execute();
		while ($row = $res->fetchArray())
		{
		    return $row['_id'];
		}

		$stm_ins = $this->prepare('INSERT INTO "Genres" (genre) VALUES (?)');
		$stm_ins->bindParam(1, $genre, SQLITE3_TEXT);
		if ($stm_ins->execute())
		    return $this->addGenre($genre);
		return 0;
    }

    function addTitle($title)
    {
		$stm_sel = $this->prepare('SELECT _id FROM "Titles" WHERE title=?;');
		$stm_sel->bindParam(1, $title, SQLITE3_TEXT);
		$res = $stm_sel->execute();
		while ($row = $res->fetchArray())
		{
	    	return $row['_id'];
		}
		$stm_ins = $this->prepare('INSERT INTO "Titles" (title) VALUES (?)');
		$stm_ins->bindParam(1, $title, SQLITE3_TEXT);
		if ($stm_ins->execute())
			return $this->addTitle($title);
		return 0;
    }

    function addFile($authors_ids, $genres_ids, $title_id, $file_name, $file_ext, $file_size, $lang, $date_add)
    {
		$stm_ins = $this->prepare('INSERT INTO "Files" (file_name, size, lang, file_ext, title_id, date_add) VALUES (?, ?, ?, ?, ?, ?)');
		$stm_ins->bindParam(1, $file_name, SQLITE3_TEXT);
		$stm_ins->bindParam(2, $file_size, SQLITE3_INTEGER);
		$stm_ins->bindParam(3, $lang, SQLITE3_TEXT);
		$stm_ins->bindParam(4, $file_ext, SQLITE3_TEXT);
		$stm_ins->bindParam(5, $title_id, SQLITE3_INTEGER);
		$stm_ins->bindParam(6, $date_add, SQLITE3_TEXT);
		if (!($stm_ins->execute()))
			return 0;

		$file_id = 0;
		$stm_sel = $this->prepare('SELECT _id FROM "Files" WHERE file_name=? AND file_ext=?;');
		$stm_sel->bindParam(1, $file_name, SQLITE3_TEXT);
		$stm_sel->bindParam(2, $file_ext, SQLITE3_TEXT);
		$res = $stm_sel->execute();
		while ($row = $res->fetchArray())
		{
	    	$file_id = $row['_id'];
		    break;
		}
		if ($file_id == 0)
		    return 0;

		$this->bindFileToAuthors($file_id, $authors_ids);
		$this->bindFileToGenres($file_id, $genres_ids);

		return $file_id;
    }

    function bindFileToAuthors($file_id, $authors_ids)
    {
		$stm_ins = $this->prepare('INSERT INTO "FilesByAuthors" (file_id, author_id) VALUES (?, ?)');
		$stm_ins->bindParam(1, $file_id, SQLITE3_INTEGER);
		foreach ($authors_ids as $author_id)
		{
	    	$stm_ins->bindParam(2, $author_id, SQLITE3_INTEGER);
		    $stm_ins->execute();
		}
    }

    function bindFileToGenres($file_id, $genres_ids)
    {
		$stm_ins = $this->prepare('INSERT INTO "FilesByGenres" (file_id, genre_id) VALUES (?, ?)');
		$stm_ins->bindParam(1, $file_id, SQLITE3_INTEGER);
		foreach ($genres_ids as $genre_id)
		{
	    	$stm_ins->bindParam(2, $genre_id, SQLITE3_INTEGER);
		    $stm_ins->execute();
		}
    }

    function getGenres()
    {
		$genres;
		$ret = $this->query("SELECT * FROM 'Genres';");
//		$ret = $this->query("SELECT * FROM 'Genres' LIMIT $count OFFSET $skip;");
		while ($row = $ret->fetchArray())
		{
	    	$genres[$row['_id']] = $row['genre'];
		}
		return $genres;
    }

    function getAuthors()
    {
		$authros;
		$ret = $this->query('SELECT * FROM "Authors";');
		while ($row = $ret->fetchArray())
		{
	    	$authors[$row['_id']] = $row['author'];
		}
		return $authors;
    }

    function getBooksByGenre($genre_id)
    {
		$sql =<<<EOF
		    SELECT Files.*, Titles.title AS title, Authors.author as author, Authors._id as author_id FROM "Files"
	    		INNER JOIN "Titles" ON Files.title_id=Titles._id
			    INNER JOIN "FilesByGenres" ON Files._id=FilesByGenres.file_id
			    INNER JOIN "FilesByAuthors" ON Files._id=FilesByAuthors.file_id
			    INNER JOIN "Authors" ON FilesByAuthors.author_id=Authors._id
		    WHERE FilesByGenres.genre_id=?
	    	ORDER BY title;
EOF;
		$files;
		$stm_sel = $this->prepare($sql);
		$stm_sel->bindParam(1, $genre_id, SQLITE3_INTEGER);
		$res = $stm_sel->execute();
		while ($row = $res->fetchArray())
		{
	    	$files[$row['_id']] = array
		    (
				'file_name' => $row['file_name'],
				'file_size' => $row['size'],
				'file_ext' => $row['file_ext'],
				'date_add' => $row['date_add'],
				'lang' => $row['lang'],
				'title' => $row['title'],
				'author' => $row['author'],
				'author_id' => $row['author_id'],
		    );
//	    	var_dump($row);
		}
		return $files;
    }

    function getBooksByAuthor($author_id)
    {
		$sql =<<<EOF
			SELECT Files.*, Titles.title AS title, Authors.author, Authors._id AS author_id FROM "Files"
				INNER JOIN "Titles" ON Files.title_id=Titles._id
				INNER JOIN "FilesByAuthors" ON Files._id=FilesByAuthors.file_id
				INNER JOIN "Authors" ON FilesByAuthors.author_id=Authors._id
			WHERE Files._id IN (
				SELECT Files._id FROM "Files"
					INNER JOIN "FilesByAuthors" ON Files._id=FilesByAuthors.file_id
				WHERE FilesByAuthors.author_id=?
			)
			ORDER BY title;
EOF;
		$files;
		$stm_sel = $this->prepare($sql);
		$stm_sel->bindParam(1, $author_id, SQLITE3_INTEGER);
		$res = $stm_sel->execute();
		while ($row = $res->fetchArray())
		{
	    	$files[$row['_id']] = array
		    (
				'file_name' => $row['file_name'],
				'file_size' => $row['size'],
				'file_ext' => $row['file_ext'],
				'date_add' => $row['date_add'],
				'lang' => $row['lang'],
				'title' => $row['title'],
				'author' => $row['author'],
				'author_id' => $row['author_id'],
		    );
//	    	var_dump($row);
		}
		return $files;
    }

    function getBookFileInfo($book_id)
    {
		$sql =<<<EOF
	    	SELECT file_name, file_ext, size FROM "Files" WHERE Files._id=?;
EOF;
		$stm_sel = $this->prepare($sql);
		$stm_sel->bindParam(1, $book_id, SQLITE3_INTEGER);
		$res = $stm_sel->execute();
		while ($row = $res->fetchArray())
		{
	    	$file_info['file_name'] = $row['file_name'];
		    $file_info['file_ext'] = $row['file_ext'];
		    $file_info['file_size'] = $row['size'];
		    return $file_info;
		}
    }

    function searchBooksByTitle($title)
    {
	$sql =<<<EOF
	    SELECT Files.*, Titles.title AS title, Authors.author as author, Authors._id as author_id FROM "Files"
	    INNER JOIN "Titles" ON Files.title_id=Titles._id
	    INNER JOIN "FilesByAuthors" ON Files._id=FilesByAuthors.file_id
	    INNER JOIN "Authors" ON FilesByAuthors.author_id=Authors._id
	    WHERE Titles.title LIKE '%' || ? || '%'
	    ORDER BY title;
EOF;
	$files;
	$stm_sel = $this->prepare($sql);
	$stm_sel->bindParam(1, $title, SQLITE3_TEXT);
	$res = $stm_sel->execute();
	while ($row = $res->fetchArray())
	{
	    $files[$row['_id']] = array
	    (
		'file_name' => $row['file_name'],
		'file_size' => $row['size'],
		'file_ext' => $row['file_ext'],
		'date_add' => $row['date_add'],
		'lang' => $row['lang'],
		'title' => $row['title'],
		'author' => $row['author'],
		'author_id' => $row['author_id'],
	    );
	}
	return $files;
    }

    function searchBooksByAuthor($author)
    {
		$sql =<<<EOF
			SELECT Files.*, Titles.title AS title, Authors.author, Authors._id AS author_id FROM "Files"
				INNER JOIN "Titles" ON Files.title_id=Titles._id
				INNER JOIN "FilesByAuthors" ON Files._id=FilesByAuthors.file_id
				INNER JOIN "Authors" ON FilesByAuthors.author_id=Authors._id
			WHERE Files._id IN (
				SELECT Files._id FROM "Files"
					INNER JOIN "FilesByAuthors" ON Files._id=FilesByAuthors.file_id
					INNER JOIN "Authors" ON FilesByAuthors.author_id=Authors._id
				WHERE Authors.author LIKE '%' || ? || '%'
			)
			ORDER BY title;
EOF;
		$files;
		$stm_sel = $this->prepare($sql);
		$stm_sel->bindParam(1, $author, SQLITE3_TEXT);
		$res = $stm_sel->execute();
		while ($row = $res->fetchArray())
		{
	    	$files[$row['_id']] = array
		    (
				'file_name' => $row['file_name'],
				'file_size' => $row['size'],
				'file_ext' => $row['file_ext'],
				'date_add' => $row['date_add'],
				'lang' => $row['lang'],
				'title' => $row['title'],
				'author' => $row['author'],
				'author_id' => $row['author_id'],
		    );
		}
		return $files;
    }
}	// End of MyDB class


function convert()
{
    global $tmp_path, $db_filename, $inpx_input;

    $db = new MyDB($tmp_path . $db_filename);
    if (!$db)
    {
		die($db->lastErrorMsg());
    }
    $db->create();

    // get the absolute path to $file
    //$path = pathinfo(realpath($file), PATHINFO_DIRNAME);

    $zip = new ZipArchive;
    $res = $zip->open($inpx_input);
    if ($res === TRUE)
    {
		// extract it to the path we determined above
		$zip->extractTo($tmp_path);
		$zip->close();
		echo "$file extracted to $tmp_path\n";
    }
    else
    {
		echo "Doh! I couldn't open $inpx_input\n";
    }

    $sep = chr(0x04);

    foreach (glob($tmp_path . '*.inp') as $file)
    {
		echo $file . "\n";
		$records = explode("\n", file_get_contents($file));
		foreach ($records as $rec)
		{
	    	$array = explode($sep, $rec);

			$authors_ids = array();
			$authors = explode(':', $array[0]);
			foreach ($authors as $author)
			{
				if (!empty($author))
				{
					$author = trim(str_replace(',', ' ', $author), " -\t\r!@#$%^&*()_=+|");
					$author_id = $db->addAuthor($author);
					//echo "$author_id -> $author\n";
					if ($author_id)
						$authors_ids[] = $author_id;
				}
			}

			$genres_ids = array();
			$genres = explode(':', $array[1]);
			foreach ($genres as $genre)
			{
				if (!empty($genre))
				{
					$genre_id = $db->addGenre($genre);
					//echo "$genre_id -> $genre\n";
					if ($genre_id)
						$genres_ids[] = $genre_id;
				}
			}

			$title = trim($array[2], " \t");
			$title_id = $db->addTitle($title);
			//echo "$title_id -> $title\n";

			$file_name = trim($array[5], " \t");
			$file_size = trim($array[6], " \t");
			$file_ext = trim($array[9], " \t");

			$date_add = trim($array[10], " \t");
			$lang = trim($array[11], "' \t");

			//echo "$file_name $file_ext\n";
			$db->addFile($authors_ids, $genres_ids, $title_id, $file_name, $file_ext, $file_size, $lang, $date_add);
		}
    }

    $db->close();

    rename($tmp_path . $db_filename, './' . $db_filename);

    // TODO: cleanup temporary directory

    exit;
}

$db = new MyDB($db_filename);
if (!$db)
{
    die($db->lastErrorMsg());
}

function beginOPDSFeed($opds, $page, $last, $title)
{
    global $search_enable;

    header('Content-Type: application/atom+xml; charset=utf-8');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><feed xmlns=\"http://www.w3.org/2005/Atom\">\r\n";
    if ($search_enable)
		echo "<link rel=\"search\" type=\"application/atom+xml;profile=opds-catalog\" xmlns:atom=\"http://www.w3.org/2005/Atom\" href=\"?opds=search&amp;terms={searchTerms}&amp;author={atom:author}&amp;title={atom:title}\" />\r\n";
    if ($page > 0)
    {
		$prev = $page - 1;
		echo "<link rel=\"previous\" href=\"?opds=$opds&amp;page=$prev\"/>\r\n";
    }
    echo "<link rel=\"self\" href=\"?opds=$opds&amp;page=$page\"/>\r\n";
    if ($last > $page)
    {
		$next = $page + 1;
		echo "<link rel=\"next\" href=\"?opds=$opds&amp;page=$next\"/>\r\n";
    }
    if ($last != 0)
    {
		echo "<link rel=\"start\" href=\"?opds=$opds&amp;page=0\"/>\r\n";
		echo "<link rel=\"last\" href=\"?opds=$opds&amp;page=$last\"/>\r\n";
    }
	echo "<title>$title</title>\r\n";
}

function endOPDSFeed()
{
    echo "</feed>\n";
}

function showGenres($parent, $genres, $page)
{
    global $items_per_page;

    $total = count($genres);
    $begin = $page * $items_per_page;
    $last = min($total, $begin + $items_per_page);

    beginOPDSFeed($parent, $page, (int)($total / $items_per_page), 'Genres');

    $keys = array_keys($genres);
    for ($ndx = $begin; $ndx < $last; $ndx++)
    {
		$id = $keys[$ndx];
		$genre = $genres[$id];
		echo "<entry><title>$genre</title><content type=\"text\">$genre</content><link href=\"?opds=$parent/$id\" type=\"application/atom+xml;profile=opds-catalog\" /></entry>\r\n";
    }

    endOPDSFeed();
}

function showAuthors($parent, $authors, $page)
{
    global $items_per_page;

    $total = count($authors);
    $begin = $page * $items_per_page;
    $last = min($total, $begin + $items_per_page);

    beginOPDSFeed($parent, $page, (int)($total / $items_per_page), 'Authors');

    $keys = array_keys($authors);
    for ($ndx = $begin; $ndx < $last; $ndx++)
    {
		$id = $keys[$ndx];
		$author = htmlspecialchars($authors[$id]);
		echo "<entry><title>$author</title><content type=\"text\">$author</content><link href=\"?opds=$parent/$id\" type=\"application/atom+xml;profile=opds-catalog\" /></entry>\r\n";
    }

    endOPDSFeed();
}

function showBooks($parent, $files, $page, $title)
{
    global $items_per_page;

    $total = count($files);
    $begin = $page * $items_per_page;
    $last = min($total, $begin + $items_per_page);

    beginOPDSFeed($parent, $page, (int)($total / $items_per_page), $title);

    if ($total > 0)
    {
		$id_prev = 0;
		$keys = array_keys($files);
		for ($ndx = $begin; $ndx < $last; $ndx++)
		{
	    	$id = $keys[$ndx];
		    if ($id != $id_prev)	// Start new entry
	    	{
				if ($id_prev != 0)
				    echo "</entry>\r\n";
				$file = $files[$id];

				$file_name = $file['file_name'];
				$file_ext = $file['file_ext'];
				$file_size = $file['file_size'];
				$date_add = $file['date_add'];
				$title = htmlspecialchars($file['title']);
				echo "<entry><updated>$date_add</updated><title>$title</title><content type=\"text\">Filename: $file_name.$file_ext Size: $file_size bytes</content><link href=\"?get=$id\" rel=\"http://opds-spec.org/acquisition/open-access\" type=\"application/fb2\" />";
				$id_prev = $id;
		    }
		    if (isset($file['author_id']))	// Add author
	    	{
				$author_id = $file['author_id'];
				$author = htmlspecialchars($file['author']);
				echo "<author><name>$author</name><uri>?opds=authors/$author_id</uri></author>";
		    }
		}

		echo "</entry>\r\n";
    }

    endOPDSFeed();
}

function showRoot()
{
    beginOPDSFeed('', 0, false, 'OPDS');

    echo "<entry><title>By genres</title><content type=\"text\">View collection by genres</content><link href=\"?opds=/genres\" type=\"application/atom+xml;profile=opds-catalog\" /></entry>\r\n";
    echo "<entry><title>By authors</title><content type=\"text\">View collection by authors</content><link href=\"?opds=/authors\" type=\"application/atom+xml;profile=opds-catalog\" /></entry>\r\n";

    endOPDSFeed();
}

function download($file_id)
{
    global $db, $lib_path;

    $file_info = $db->getBookFileInfo($file_id);
    $file_name = $file_info['file_name'];
    $file_ext = $file_info['file_ext'];
    $file_size = $file_info['file_size'];

    foreach (glob(preg_replace('/(\*|\?|\[)/', '[$1]', $lib_path) .  '*') as $file)
    {
	$array = explode('-', $file);
	if (((int)$file_name >= (int)$array[2]) and ((int)$file_name <= (int)$array[3]))
	{
	    $full_filename = $file_name . '.' . $file_ext;

	    header('Content-Description: File Transfer');
	    header('Content-Disposition: attachment; filename=' . $full_filename );
	    header('Content-Length: ' . $file_size);
	    header('Content-Type: application/octet-stream');
	    header('Content-Transfer-Encoding: binary');
	    readfile('zip://' . $file . '#' . $full_filename);
	    exit;
	}
    }
    header("HTTP/1.0 404 Not Found");
}


$page = 0;
if (isset($_GET['page']))
    $page = $_GET['page'];

if (isset($_GET['get']))
{
    download($_GET['get']);
}
else if (isset($_GET['convert']))
{
    if ($convert_enable)
		convert();
}
else if (isset($_GET['opds']))
{
    $opds = $_GET['opds'];

    $parent = '';

    $levels = explode('/', $opds);

    $count = count($levels);

    if (empty($opds) || $count == 0)
    {
		showRoot();
		exit;
    }

    $lvl_last = $levels[$count - 1];

    if ($lvl_last === 'authors')
    {
		$authors = $db->getAuthors();
		showAuthors($opds, $authors, $page);
    }
    elseif ($lvl_last === 'genres')
    {
		$genres = $db->getGenres();
		showGenres($opds, $genres, $page);
    }
    elseif ($lvl_last === 'last')
    {
		// Show newest
    }
    elseif ($lvl_last === 'search')
    {
		$terms;
		if (isset($_GET['terms']))
		{
		    $terms = $_GET['terms'];
	    	if (mb_check_encoding($terms, $default_encoding) && !mb_check_encoding($terms, 'UTF-8'))
				$terms = mb_convert_encoding($terms, 'UTF-8', $default_encoding);
		}

		if ((!isset($terms)) || (empty($terms)))
		{
		    if (isset($_GET['title']))
	    	{
				$terms = $_GET['title'];
				if (mb_check_encoding($terms, $default_encoding) && !mb_check_encoding($terms, 'UTF-8'))
			    	$terms = mb_convert_encoding($terms, 'UTF-8', $default_encoding);
		    }
		}

		if ((!isset($terms)) || (empty($terms)))
		{
		    if (!isset($_GET['author']))
				exit;
		    $author = $_GET['author'];
		    if (mb_check_encoding($author, $default_encoding) && !mb_check_encoding($author, 'UTF-8'))
				$author = mb_convert_encoding($author, 'UTF-8', $default_encoding);
		    if (!empty($author))
		    {
				$books = $db->searchBooksByAuthor($author);
				showBooks($opds, $books, $page, "Search results: ".$author);
				exit;
		    }
		}
		else
		{
		    $books = $db->searchBooksByTitle($terms);
	    	showBooks($opds, $books, $page, "Search results: ".$terms);
		    exit;
		}
    }
    elseif ($count > 1)
    {
		$lvl_parent = $levels[$count - 2];
		if ($lvl_parent === 'genres')
		{
		    $books = $db->getBooksByGenre($lvl_last);
	    	showBooks($opds, $books, $page, "Genre: ".$lvl_last);
		}
		if ($lvl_parent === 'authors')
		{
		    $books = $db->getBooksByAuthor($lvl_last);
	    	showBooks($opds, $books, $page, "Author: ".$lvl_last);
		}
    }
}
else
{
    showRoot();
}

?>
