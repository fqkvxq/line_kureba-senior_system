# Build initial SQLite database using PowerShell
$html = [System.IO.File]::ReadAllText('C:\Users\Kawai\.gemini\antigravity-ide\brain\28b28c06-90da-4b59-b51a-b9d2c0510896\scratch\stock_utf8.html', [System.Text.Encoding]::UTF8)

# Parse cars
$carBlocks = [regex]::Matches($html, '(?s)<div class="box_item_detail[^"]*" id="tr_([^"]+)"[^>]*>(.*?)<!--// \.application -->')

# Generate SQL script
$sql = @"
CREATE TABLE IF NOT EXISTS cars (
    id TEXT PRIMARY KEY,
    shop_code TEXT NOT NULL,
    title TEXT NOT NULL,
    total_price_text TEXT,
    total_price_num REAL,
    base_price_text TEXT,
    base_price_num REAL,
    year TEXT,
    distance TEXT,
    distance_num REAL,
    displacement TEXT,
    repair_history TEXT,
    shaken TEXT,
    image_url TEXT,
    detail_url TEXT,
    is_active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_cars_active ON cars(is_active);
CREATE INDEX IF NOT EXISTS idx_cars_price ON cars(total_price_num);
CREATE INDEX IF NOT EXISTS idx_cars_title ON cars(title);

"@

foreach ($block in $carBlocks) {
    $id = $block.Groups[1].Value
    $content = $block.Groups[2].Value
    
    $title = ""
    if ($content -match '(?s)<h3 class="car_box_title">.*?<a[^>]*>(.*?)</a>') {
        $title = ($matches[1] -replace '<[^>]+>', '' -replace '\s+', ' ').Trim()
    }
    
    $link = "https://www.goo-net.com/usedcar/spread/goo/15/$id.html"
    
    $img = ""
    if ($content -match 'src="(https://picture1\.goo-net\.com/[^"]+)"') {
        $img = $matches[1]
    }
    
    $totalPriceText = ""
    $totalPriceNum = "NULL"
    if ($content -match '(?s)<div class="priceAllNum"><em>(.*?)</em><span>(.*?)</span>') {
        $num = $matches[1].Trim()
        $unit = $matches[2].Trim()
        $totalPriceText = $num + $unit
        if ($num -match '^[0-9\.]+$') { $totalPriceNum = $num }
    }
    
    $basePriceText = ""
    $basePriceNum = "NULL"
    if ($content -match '(?s)<p class="car">.*?<em>(.*?)</em><span>(.*?)</span>') {
        $bNum = $matches[1].Trim()
        $bUnit = $matches[2].Trim()
        $basePriceText = $bNum + $bUnit
        if ($bNum -match '^[0-9\.]+$') { $basePriceNum = $bNum }
    }
    
    $year = ""
    $distance = ""
    $distNum = "NULL"
    $displacement = ""
    $repair = ""
    $shaken = ""
    
    if ($content -match '(?s)<table>.*?<tr>(.*?)</tr>.*?</table>') {
        $row = $matches[1]
        $tds = [regex]::Matches($row, '(?s)<td[^>]*>(.*?)</td>')
        if ($tds.Count -ge 6) {
            $year = ($tds[1].Groups[1].Value -replace '<[^>]+>', '' -replace '\s+', ' ').Trim()
            $distance = ($tds[2].Groups[1].Value -replace '<[^>]+>', '' -replace '\s+', ' ').Trim()
            $displacement = ($tds[3].Groups[1].Value -replace '<[^>]+>', '' -replace '\s+', ' ').Trim()
            $repair = ($tds[4].Groups[1].Value -replace '<[^>]+>', '' -replace '\s+', ' ').Trim()
            $shaken = ($tds[5].Groups[1].Value -replace '<[^>]+>', '' -replace '\s+', ' ').Trim()
            
            if ($distance -match '([0-9\.]+)\s*万km') {
                $distNum = $matches[1]
            } elseif ($distance -match '([0-9,]+)\s*km') {
                $distNum = [string]([double]($matches[1] -replace ',', '') / 10000.0)
            }
        }
    }
    
    $safeTitle = $title -replace "'", "''"
    $safeTotalText = $totalPriceText -replace "'", "''"
    $safeBaseText = $basePriceText -replace "'", "''"
    $safeYear = $year -replace "'", "''"
    $safeDist = $distance -replace "'", "''"
    $safeDisp = $displacement -replace "'", "''"
    $safeRepair = $repair -replace "'", "''"
    $safeShaken = $shaken -replace "'", "''"
    $safeImg = $img -replace "'", "''"
    $safeUrl = $link -replace "'", "''"

    $sql += @"
INSERT OR REPLACE INTO cars (id, shop_code, title, total_price_text, total_price_num, base_price_text, base_price_num, year, distance, distance_num, displacement, repair_history, shaken, image_url, detail_url, is_active, updated_at)
VALUES ('$id', '0601492', '$safeTitle', '$safeTotalText', $totalPriceNum, '$safeBaseText', $basePriceNum, '$safeYear', '$safeDist', $distNum, '$safeDisp', '$safeRepair', '$safeShaken', '$safeImg', '$safeUrl', 1, CURRENT_TIMESTAMP);

"@
}

[System.IO.File]::WriteAllText('C:\Users\Kawai\.gemini\antigravity-ide\scratch\line_car_system\batch\init_cars.sql', $sql, [System.Text.Encoding]::UTF8)
Write-Output "Generated init_cars.sql with $($carBlocks.Count) cars"
