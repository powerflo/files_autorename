# AutoRename

AutoRename is a Nextcloud app that automatically renames and organizes newly added or moved files based on user-defined rules.

## How It Works

- Place a `.rename.conf` file in a folder to define renaming and moving rules using regular expressions.
- When files are uploaded or moved into that folder, they will be renamed or moved according to the rules.
- A background job runs every few minutes to process the files, so changes may not be immediate.

## Features

✅ Define renaming and moving rules with regular expressions  
✅ Automatically process newly added or moved files  
✅ Organize files into subfolders dynamically  
✅ Use placeholders to insert metadata such as the current date, file modification time, or EXIF data in filenames  

## Installation

1. Install [AutoRename via the Nextcloud App Store](https://apps.nextcloud.com/apps/files_autorename) or manually place the app in the `apps-extra/` directory.
2. Enable the app in the Nextcloud admin panel.
3. Ensure background jobs (cron) are configured for Nextcloud.

## Configuration

To use AutoRename, create a `.rename.conf` file in the desired folder. The file should contain rules in the following format:

`pattern:replacement`

The pattern is a regular expression to match the original file name. You can learn more and test your regex patterns at [regex101.com](https://regex101.com)

### Example Rules

```
# Rename "Entgeltabrechnung_Januar_2022 aybabtu.pdf" to "2022-01_Entgeltabrechnung aybabtu.pdf":
^(Entgeltabrechnung_Januar)_(\d{4})(.*)(\.pdf)$:$2-01_$1$3$4
^(Entgeltabrechnung_Februar)_(\d{4})(.*)(\.pdf)$:$2-02_$1$3$4
^(Entgeltabrechnung_März)_(\d{4})(.*)(\.pdf)$:$2-03_$1$3$4
^(Entgeltabrechnung_April)_(\d{4})(.*)(\.pdf)$:$2-04_$1$3$4
^(Entgeltabrechnung_Mai)_(\d{4})(.*)(\.pdf)$:$2-05_$1$3$4
^(Entgeltabrechnung_Juni)_(\d{4})(.*)(\.pdf)$:$2-06_$1$3$4
^(Entgeltabrechnung_Juli)_(\d{4})(.*)(\.pdf)$:$2-07_$1$3$4
^(Entgeltabrechnung_August)_(\d{4})(.*)(\.pdf)$:$2-08_$1$3$4
^(Entgeltabrechnung_September)_(\d{4})(.*)(\.pdf)$:$2-09_$1$3$4
^(Entgeltabrechnung_Oktober)_(\d{4})(.*)(\.pdf)$:$2-10_$1$3$4
^(Entgeltabrechnung_November)_(\d{4})(.*)(\.pdf)$:$2-11_$1$3$4
^(Entgeltabrechnung_Dezember)_(\d{4})(.*)(\.pdf)$:$2-12_$1$3$4

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

## Notes

- The first matching rule in `.rename.conf` will be applied.
- Only newly added or moved files are processed; existing files are not renamed.
- Processing occurs in a background job, so file renaming may take a few minutes.

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