# AutoRename

AutoRename is a Nextcloud app that automatically renames and organizes newly added or moved files based on user-defined rules.

## How It Works

- Place a `.rename.conf` file in a folder to define renaming rules.
- If the new name includes subfolders (e.g. `subfolder/new_name`), the file will also be moved.
- When files are uploaded or moved into that folder, the rules will be applied during the next background job.

### Key Features

✅ Define powerful renaming and moving rules using regular expressions  
✅ Use placeholders to insert metadata such as the current date, file modification time, or EXIF data (for photos) in filenames  

## Installation

1. Install [AutoRename via the Nextcloud App Store](https://apps.nextcloud.com/apps/files_autorename) or manually place the app in the `apps-extra/` directory.
2. Enable the app in the Nextcloud admin panel.
3. Ensure background jobs (cron) are configured for Nextcloud.

## Configuration

Create a `.rename.conf` file in the desired folder.

Rules are defined using regular expression (regex) patterns to match the original file name, and replacement strings to generate the new file name.

A rule can be either:

- A single `pattern:replacement` pair on one line, or

- A rule with multiple `pattern:replacement` pairs enclosed in curly braces `{}`.

If the **first pattern** of a rules matches the original file name, **all replacements** in the rule are applied in order. Once a rule matches and is applied, **no further rules are evaluated**.

You can learn more and test your regex patterns at [regex101.com](regex101.com).

### Example Rules

```
# Rename "Entgeltabrechnung_January_2022 asdf.pdf" to "2022-01_Entgeltabrechnung asdf.pdf":
{
^Entgeltabrechnung_(.*)_(\d{4})(.*)\.pdf$:$2-$1_Entgeltabrechnung$3.pdf
January:01
February:02
March:03
April:04
May:05
June:06
July:07
August:08
September:09
October:10
November:11
December:12
}

# Move account statements to "Kontoauszüge/YEAR/FILENAME"
^Kontoauszug.*_(20\d{2})_.*\.pdf$:Kontoauszüge/$1/$0

# Move securities documents to "Wertpapierdokumente/YEAR/FILENAME"
^Ertragsabrechnung.*\.(20\d{2})_.*\.pdf$:Wertpapierdokumente/$1/$0
^Depotauszug.*\.(20\d{2})_.*\.pdf$:Wertpapierdokumente/$1/$0

# Change date format in filename from dd.mm.yyyy to yyyy-mm-dd
(.*)(\d{2})\.(\d{2})\.(20\d{2})(.*):$1$4-$3-$2$5

# Use the current date in the filename. Refer to the FAQ for a complete list of available placeholders.
# Rename "report.pdf" to "report_2025-02-10.pdf" (assuming today's date is 2025-02-10)
^(report)(\.pdf)$:$1_{date}$2
```

# FAQ

### How to contribute?
Contributions are welcome! Feel free to submit issues and pull requests.

### Why isn’t AutoRename renaming files on my external storage?

If files are copied directly to the external storage, AutoRename can not trigger the renaming process. Instead, upload files through the Nextcloud web interface, mobile app, or desktop client.

### What placeholders can I use in the replacement string?

You can use the following placeholders to automatically inject metadata into the new file name:

| Placeholder            | Description                                                                 |
|------------------------|-----------------------------------------------------------------------------|
| `{photoDateTime}`      | Original date/time the photo was taken from EXIF metadata. If that's not available, it falls back to the file modification time. |
| `{exifDateTimeOriginal}` | Original date/time the photo was taken, extracted from EXIF metadata. Returns an empty string if EXIF data is not available. **Note: The Photos app must be installed for EXIF metadata to be available.** |
| `{fileModifiedAt}`     | The file's last modified timestamp, from the file system.                   |
| `{date}`               | The current date and time.                      |

### How can I customize the date/time format of the placeholder?

To insert a date or time in a specific format, use the syntax `{placeholder|format}`, where `format` follows PHP's date formatting options.

#### Example:

- `{date|Y-m-d}` → `2025-02-10` (default format)
- `{date|m/d/y}` → `02/10/25`​
- `{date|Y-m-d_H-i-s}` → `2025-02-10_14-30-15` (Year-Month-Day_Hour-Minute-Second)

#### Common format characters:

- `d`: Day of the month, two digits (e.g., `01` to `31`)​
- `m`: Month, two digits (e.g., `01` to `12`)​
- `Y`: Year, four digits (e.g., `2025`)​
- `y`: year, two digits (e.g., `25`)
- `H`: Hour in 24-hour format (e.g., `00` to `23`)
- `i`: Minute, two digits (e.g., `00` to `59`)
- `s`: Second, two digits (e.g., `00` to `59`)

For a full list of formatting options, refer to the official PHP documentation: https://www.php.net/manual/en/datetime.format.php.

### How do I create a `.rename.conf` file?

1. Open your Nextcloud and navigate to the folder where you want to set up rename rules.  
2. Click the **“+ New”** button at the top and choose **“New text file”**.  
3. Name the file `.rename.conf` (including the dot at the beginning) and click **Create**.  
4. You can now add your rename rules directly in the file.

Alternatively, you can create the `.rename.conf` file locally on your computer and upload it to the desired folder in Nextcloud — just like you would with any other file.

### Why can’t I see the `.rename.conf` file in the folder?

Files that start with a dot (like `.rename.conf`) are hidden by default.  
To view hidden files in Nextcloud:

1. Click **Settings** at the bottom left corner of the file browser.
2. Enable the **“Show hidden files”** option.

### Which regex syntax is supported?

The AutoRename app passes the patterns and replacements defined in `.rename.conf` directly to PHP’s [`preg_replace`](https://www.php.net/manual/en/function.preg-replace.php) function to determine the new file name. This means your regex must follow the **PCRE2 (Perl Compatible Regular Expressions)** syntax.

While regex patterns in PHP code are typically written with delimiters (like `/pattern/`), **patterns in the `.rename.conf` file must be written *without* these delimiters**. Just use the raw pattern, like:

```conf
^foo_(.*)\.txt$
```

### How can I test the rules?
Writing regex can be tedious and error-prone. Always test your patterns and replacements on [regex101.com](regex101.com) using the **PHP flavor (PCRE2)**.

To test your replacement strings as well, open the **"Substitution"** tab in the left panel. This allows you to simulate how your replacement will behave with your pattern—perfect for catching mistakes before they affect your files.

### How can I fix infinite renaming loops?
Make sure your rules are designed so that the **new file name does not match the pattern again**, or the renaming will repeat in a loop.

For example, if you want to prepend a timestamp to the filename, make sure to exclude files that already start with a timestamp. Here's a rule that does just that:
```
^(?!\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}).*:{date|Y-m-d_H-i-s}_$0
```
This rule appends the current date and time (e.g., `2025-04-10_15-30-00_`) to the beginning of the file name—but only if it doesn’t already start with a timestamp in that format.