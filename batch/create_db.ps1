# Create cars.db using .NET System.Data.SQLite if available, or generate via a small node or python or powershell script.
# In PowerShell 5+, we can test if SQLite connection works or use Windows.Storage or ODBC/OLEDB,
# or we can test if php / sqlite3 CLI is available.

$dbPath = "C:\Users\Kawai\.gemini\antigravity-ide\scratch\line_car_system\batch\cars.db"
$sqlPath = "C:\Users\Kawai\.gemini\antigravity-ide\scratch\line_car_system\batch\init_cars.sql"

# Check if php is in path
$php = Get-Command php -ErrorAction SilentlyContinue
if ($php) {
    php -r "
        \$db = new PDO('sqlite:{$dbPath}');
        \$sql = file_get_contents('{$sqlPath}');
        \$db->exec(\$sql);
        echo 'cars.db created successfully via PHP!\n';
    "
} else {
    Write-Output "PHP not in path, init_cars.sql ready to be run on server or by scraper."
}
