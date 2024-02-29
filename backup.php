<?php
    // go through all the clients database
    // Database connection parameters
	date_default_timezone_set('Africa/Nairobi');
    require_once __DIR__ . '/vendor/autoload.php';

    // Database connection parameters
    $hostname = 'localhost';
    $username = 'root';
    $password = '';
    $database = 'mikrotik_cloud_manager';

    // Connect to MySQL
    $conn = mysqli_connect($hostname, $username, $password, $database);

    // Check connection
    if (!$conn) {
        die("Connection failed: " . mysqli_connect_error());
    }

    $select = "SELECT * FROM `organizations`;";
    $res = mysqli_query($conn, $select);
    $date = date("YmdHis");
        
    // Directory for backup file
    $backup_dir = 'backup_'.$date;
    // $backup_dir = 'backup';

    while ($rowed = mysqli_fetch_assoc($res)) {
        // define
        $database = $rowed['organization_database'];
        
        // Connect to MySQL
        $connection = mysqli_connect($hostname, $username, $password, $database);
        
        // Check connection
        if (!$connection) {
            echo "Connection failed: " . mysqli_connect_error()."<br>";
            continue;
        }
        
        // Create backup directory if not exists
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0777, true); // Create directory recursively with full permissions
        }
        
        // Name of the backup file
        $backup_file = $backup_dir.'/'.$database.'.sql';
        
        // Fetch all tables in the database
        $tables = array();
        $result = mysqli_query($connection, "SHOW TABLES");
        while ($row = mysqli_fetch_row($result)) {
            $tables[] = $row[0];
        }
        
        // Iterate through each table
        foreach ($tables as $table) {
            // Fetch table structure
            $result = mysqli_query($connection, "SHOW CREATE TABLE $table");
            $row = mysqli_fetch_row($result);
            $table_structure = $row[1];
        
            // Write table structure to backup file
            file_put_contents($backup_file, "\n\n" . $table_structure . ";\n\n", FILE_APPEND);
        
            // Fetch table data
            $result = mysqli_query($connection, "SELECT * FROM $table");
            while ($row = mysqli_fetch_assoc($result)) {
                // Generate INSERT queries for table data
                $insert_query = "INSERT INTO $table VALUES (";
                foreach ($row as $value) {
                    $insert_query .= "'" . mysqli_real_escape_string($connection, $value) . "', ";
                }
                $insert_query = rtrim($insert_query, ', ') . ");\n";
                // Write INSERT queries to backup file
                file_put_contents($backup_file, $insert_query, FILE_APPEND);
            }
        }
        
        // Close connection
        mysqli_close($connection);
        
        // Set file permissions to readable and writable by owner, readable by others
        chmod($backup_file, 0777);


        // upload the file in drive then delete when done
        $file_id = upload_file_to_drive($backup_file);

        // success message
        echo "Backup completed. Check $backup_file (id : $file_id) for the exported database.<br>";

        // break;
    }

    // delete directory
    delete_directory($backup_dir);

    function delete_directory($dir_path) {
        if (is_dir($dir_path)) {
            $files = scandir($dir_path);
            foreach ($files as $file) {
                if ($file != "." && $file != "..") {
                    if (is_dir("$dir_path/$file")) {
                        delete_directory("$dir_path/$file");
                    } else {
                        unlink("$dir_path/$file");
                    }
                }
            }
            if (rmdir($dir_path)) {
                // echo "Directory deleted successfully.";
            } else {
                // echo "Error deleting directory.";
            }
        } else {
            // echo "Directory does not exist.";
        }
    }

    function upload_file_to_drive($file_locale){


        // Your Google Drive credentials
        $client_id = '919822737892-u6gl0omh7ojk1jc3l4ej858l1521tlhe.apps.googleusercontent.com';
        $client_secret = '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';
        $refresh_token = '1//04KEUltN-z69SCgYIARAAGAQSNwF-L9Ir6fdXpspYrNJSCycmCzOCSYsJuFbIgOhrvh_U8SQQ3bZS1XBa-Ot9iKzv6At9OH3QXYM';
        
        // Create Google Client
        $client = new Google_Client();
        $client->setClientId($client_id);
        $client->setClientSecret($client_secret);
        $client->refreshToken($refresh_token);
        $client->addScope(Google_Service_Drive::DRIVE);

        // Create Google Drive Service
        $service = new Google_Service_Drive($client);
        /** */
        // Explode the path into individual folder names
        $folder_path = "billing_backup/".date("Ymd")."/".date("His");
        $folders = explode('/', $folder_path);

        // Initialize parent folder ID to root
        $parent_id = "root";

        // Loop through each folder in the path
        foreach ($folders as $folder_name) {
            $optParams = array(
                'q' => "mimeType='application/vnd.google-apps.folder' and name='$folder_name' and '$parent_id' in parents",
                'spaces' => 'drive',
                'fields' => 'files(id, name)',
            );
            $results = $service->files->listFiles($optParams);
    
            if (count($results->getFiles()) == 0) {
                // The folder does not exist, create it
                $folder_metadata = new Google_Service_Drive_DriveFile(array(
                    'name' => $folder_name,
                    'mimeType' => 'application/vnd.google-apps.folder',
                    'parents' => array($parent_id)
                ));
                $folder = $service->files->create($folder_metadata, array('fields' => 'id'));
                $parent_id = $folder->id; // Update parent ID for the next iteration
            } else {
                // The folder exists, get its ID for the next iteration
                $parent_id = $results->getFiles()[0]->getId();
            }
            echo $parent_id."<br>";
        }
        /** */


        // File to upload
        $file_path = $file_locale;
        $file_name = basename($file_path);

        // Create file metadata
        $filename_without_extension = pathinfo($file_path, PATHINFO_FILENAME);
        $file_metadata = new Google_Service_Drive_DriveFile(array(
            'name' => $file_name,
            'parents' => array($parent_id), // Set the target folder ID as the parent
            'description' => "Backup at for \"".$filename_without_extension."\" ".date("D dS M Y: h:i:sA")
        ));

        // Upload file
        $content = file_get_contents($file_path);
        $file = $service->files->create($file_metadata, array(
            'data' => $content,
            'mimeType' => 'application/sql', // Set MIME type to SQL
            'uploadType' => 'multipart'
        ));

        // Print file ID of the uploaded file
        return $file->id;
    }
?>
