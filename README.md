# AutoRename

**AutoRename** is a Nextcloud app that automatically renames and organizes files based on user-defined rules.

## Overview

AutoRename processes files when they are **uploaded**, **moved**, or **renamed** in a folder containing a `.rename.conf` file. For user-wide rules, a `.rename.user.conf` can be placed in the user's root folder. Rules use **regular expressions (regex)** to match file names and define how they should be renamed or moved. These rules are applied during the next background job.

### Key Features

- 📝 Define renaming rules using powerful regular expressions.
- 📂 Move files to subfolders based on their new name (e.g., subfolder/new_name).
- 📅 Insert metadata like dates, EXIF data, or file modification times into filenames using [placeholders](#what-placeholders-can-i-use-in-the-replacement-string).
- 🔍 Extract [text from PDFs](#pdfPatternMatch) for custom filenames.
- 🔄 Transform filenames to [uppercase or lowercase](#how-do-i-rename-files-with-upperlowercase-changes).

## Installation

1. Install [AutoRename via the Nextcloud App Store](https://apps.nextcloud.com/apps/files_autorename) or manually place the app in the `apps-extra/` directory.
2. Enable the app in the Nextcloud admin panel under Settings > Apps.
3. Ensure background jobs (cron) are configured for Nextcloud.

## Quick Start

1. Place a `.rename.conf` file in the desired folder.
2. Add a rule like this:
    ```
    ^Invoice_(\d{4})_(\d{2})\.pdf$:Invoices/$1/$2/$0
    ```
3. Upload a matching file (e.g., `Invoice_2025_04.pdf`) — it will be renamed to `Invoices/2025/04/Invoice_2025_04.pdf` during the next background job run.


## Configuration

File renaming is configured using [per-folder](#per-folder-rules-renameconf) `.rename.conf` or [user-wide](#user-wide-rules-renameuserconf) `.rename.user.conf` rules.  See [Writing Rules](#writing-rules) for tips on rule writing and placeholders.

### Rule Syntax

Rename rules are defined using regular expression (regex) patterns to match file names or paths, and replacement strings to specify the new name or path. Rules can be written as:

- A **single** `pattern:replacement` pair on one line.  
  Searches the file name for matches to the pattern and replaces them with replacement.

- A **group** of multiple `pattern:replacement` pairs within `{}`:
    ```
    {
    pattern1:replacement1
    pattern2:replacement2
    }
    ```

  If the **first pattern** in the group has any match to the original file name, **all pattern:replacement pairs** are applied in order:

  - Each `pattern` is replaced by its corresponding `replacement`.
  - Each replacement operates on the result of the previous one (not the original file name).

### Rule Behavior

This is how rules are processed and applied:

- Rules are evaluated **in the order they appear** in the configuration file.
- **Only the first matching rule** is applied to each file. After that, no further rules are checked.
- If the replacement includes a subfolder (e.g., `subfolder/new_name`), the file is moved accordingly.
- If no rule matches, the file name remains unchanged.

### Per-Folder Rules: `.rename.conf`

Place a `.rename.conf` file in any folder to define rules specific to that folder. These rules:
- Apply to file names.
- Can move files to subfolders of the current folder.

#### Example Rules
```
# Sort PDF invoices by year/month: e.g. rename "Invoice_2025_04.pdf" to "Invoices/2025/04/Invoice_2025_04.pdf"
^Invoice_(\d{4})_(\d{2})\.pdf$:Invoices/$1/$2/$0

# Fallback: Move other invoice-related PDFs not matched above to "Invoices/Unsorted"
# The pattern uses (?i) for case-insensitive matching, so it also catches "invoice", "Invoice", "INVOICE", etc.
(?i)^.*invoice.*\.pdf$:Invoices/Unsorted/$0

# Change date format in filename from dd.mm.yyyy to yyyy-mm-dd, e.g. rename "01.02.2024" to "2024-02-01"
(\d{2})\.(\d{2})\.(\d{4}):$3-$2-$1

# Rename "report.pdf" to include the current date, e.g. "2025-04-11_report.pdf"
^(report)(\.pdf)$:{date|Y-m-d}_$1$2

# Prefix photos with EXIF capture date and time, e.g. rename "IMG_1234.jpg" to "25-04-11 15-30-00 IMG_1234.jpg"
# The pattern only matches, if the filename doesn't already start with a timestamp in that format
^(?!\d{2}-\d{2}-\d{2}(\s|_)\d{2}-\d{2}-\d{2}).*\.(jpg|JPG|jpeg)$:{photoDateTime|y-m-d H-i-s} $0

# Example to rename "Report_January_2022.pdf" to "2022-01_Report.pdf"
# The first line reformats the filename to "2022-January_Report.pdf"
# Then each following line replaces a month name with its numeric equivalent
{
^Report_(\w+)_(\d{4})\.pdf$:$2-$1_Report.pdf
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

# Use ISIN, date, and transaction type extracted from a Trade Republic securities document
{
^(Wertpapierabrechnung|Abrechnungsausführung|Abrechnung|Vorabpauschale).*\.pdf$:DATUM_ISIN_Abrechnung_TYPE.pdf
ISIN:{pdfPatternMatch|/([A-Z]{2}[A-Z0-9]{9}\d)\s/|isin_not_found}
DATUM:{pdfPatternMatch|/DATUM\s+(\d{2}\.\d{2}\.20\d{2})/|date_not_found}
TYPE:{pdfPatternMatch|/(Kauf|Verkauf|Sparplan|Saveback|Dividende|Vorabpauschale)/|type_not_found}
(\d{2})\.(\d{2})\.(\d{4}):$3-$2-$1
}
```

### User-Wide Rules: `.rename.user.conf`

For more advanced use cases, place a `.rename.user.conf` file at the root of a user's Nextcloud folder to define rules that apply to **files owned by the user**. These rules:

- Match the **relative file path** inside the user's folder.
- Define the **new file path**, allowing files to be moved anywhere within the user's scope.
- Are used only if no `.rename.conf` exists in the file's folder.
- Do not apply to external storage or group folders (use `.rename.conf` for those).

#### Example

To organize PDFs uploaded via Nextcloud's file request feature (e.g., `submissions/{studentID}/homework1.pdf`):

```
^submissions/([^/]+)/(.+\.pdf)$:submissions/$1_$2
```

This rule:

- Matches PDFs in `submissions/*/` subfolders.
- Moves them to the `submissions/` folder, prefixing the filename with the subfolder name (e.g., `studentID_homework1.pdf`).

### Groupfolder-Wide rules: `rename.groupfolder.conf`

Define global renaming rules for a **group folder** (also known as **Team folders**) by placing a `rename.groupfolder.conf` file at the top level of a group folder. These rules:

- Match the **relative file path** within the group folder.
- Define the **new file path**, allowing files to be moved anywhere within the group folder.
- Are used only if no `.rename.conf` exists in the file's folder.

# FAQ

- [Table of Contents](#faq)
    - [How to contribute?](#how-to-contribute)
    - [What happens if a file with the new file name (as a result of a renaming rule) already exists?](#what-happens-if-a-file-with-the-new-file-name-as-a-result-of-a-renaming-rule-already-exists)
    - [What happens if the target folder for the file (when moving it) does not exist?](#what-happens-if-the-target-folder-for-the-file-when-moving-it-does-not-exist)
    - [Can folders be renamed or moved?](#can-folders-be-renamed-or-moved)
- [Writing rules](#writing-rules)
    - [Need help writing rules?](#need-help-writing-rules)
    - [How can I test the rules?](#how-can-i-test-the-rules)
    - [What placeholders can I use in the replacement string?](#what-placeholders-can-i-use-in-the-replacement-string)
    - [How can I customize the date/time format of the placeholder?](#how-can-i-customize-the-datetime-format-of-the-placeholder)
    - [How do I rename files with upper/lowercase changes?](#how-do-i-rename-files-with-upperlowercase-changes)
    - [Which regex syntax is supported?](#which-regex-syntax-is-supported)
    - [How can I avoid infinite renaming loops?](#how-can-i-avoid-infinite-renaming-loops)
    - [How can I use `:` in the pattern and replacement?](#how-can-i-use--in-the-pattern-and-replacement)
    - [How can I use `/` in the pattern and replacement?](#how-can-i-use--in-the-pattern-and-replacement-1)

- [Configuration file](#configuration-file)
    - [How do I create a .rename.conf file?](#how-do-i-create-a-renameconf-file)
    - [Why can’t I see the .rename.conf file in the folder?](#why-cant-i-see-the-renameconf-file-in-the-folder)
- [Troubleshooting](#troubleshooting)
    - [What should I do if a file is not renamed but I expect it to be renamed?](#what-should-i-do-if-a-file-is-not-renamed-but-i-expect-it-to-be-renamed)
    - [Will existing files be renamed after I create or update a `.rename.conf` file?](#will-existing-files-be-renamed-after-i-create-or-update-a-renameconf-file)
    - [Why isn’t AutoRename renaming files on my external storage?](#why-isnt-autorename-renaming-files-on-my-external-storage)



### How to contribute?

Contributions are welcome! Feel free to submit issues and pull requests.

### What happens if a file with the new file name (as a result of a renaming rule) already exists?

The file will **not be renamed** to avoid overwriting existing files.

### What happens if the target folder for the file (when moving it) does not exist?

The target folder will be **created automatically** to ensure the file can be moved there.

### Can folders be renamed or moved?

No, AutoRename is currently restricted to **files** and does not rename or move folders.

## Writing rules

### Need help writing rules?

Try asking ChatGPT or other AI tools for help with writing rules. Provide a clear example and context, like this:

```
Use the README https://github.com/powerflo/files_autorename/blob/main/README.md to answer the following request. Only answer if you have access to the README.
Write a rule to rename the file report_10.10.2010.pdf to 2010-10-10_report.pdf.
```

If needed, you can also ask the community for additional support.

### How can I test the rules?

Writing regex can be tedious and error-prone. Always test your patterns and replacements on [regex101.com](https://regex101.com) using the **PHP flavor (PCRE2)**.

To test replacements, open the **"Substitution"** tab in the left panel—this simulates exactly how your replacement will behave.

### What placeholders can I use in the replacement string?

You can use the following placeholders to automatically inject metadata into the new file name:

| Placeholder            | Description                                                                 |
|------------------------|-----------------------------------------------------------------------------|
| `{photoDateTime}` <br> `{photoDateTime\|format}`      | Original date/time the photo was taken from EXIF metadata. If that's not available, it falls back to the file modification time. <br> *Note: The Photos app must be installed for EXIF metadata to be available.* |
| `{exifDateTimeOriginal}` <br> `{exifDateTimeOriginal\|format}` | Original date/time the photo was taken, extracted from EXIF metadata. Returns an empty string if EXIF data is not available. <br> *Note: The Photos app must be installed for EXIF metadata to be available.* |
| `{fileModifiedAt}` <br> `{fileModifiedAt\|format}`     | The file's last modified timestamp, from the file system.                   |
| `{date}` <br> `{date\|format}`               | The current date and time.                      |
| <a id="pdfPatternMatch"></a>`{pdfPatternMatch\|/pattern/}` <br> `{pdfPatternMatch\|/pattern/\|fallback}` | Extracts text from the content of a PDF using a regex pattern. If no match is found or an error occurs while parsing the file, the `fallback` (if provided) is used. Useful for filenames based on invoice numbers, dates, or identifiers within PDFs. |

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

### How do I rename files with upper/lowercase changes?

You can use `upper(...)` and `lower(...)` to transform parts of the replacement string to uppercase or lowercase.

Example rule to rename `file.txt` to `FILE.txt`:
  
```
^(.*)\.txt$:upper($1).txt
```

### Which regex syntax is supported?

The AutoRename app passes the patterns and replacements defined in `.rename.conf` directly to PHP’s [`preg_replace`](https://www.php.net/manual/en/function.preg-replace.php) function to determine the new file name. This means your regex must follow the **PCRE2 (Perl Compatible Regular Expressions)** syntax.

While regex patterns in PHP code are typically written with delimiters (like `/pattern/`), **patterns in the `.rename.conf` file must be written *without* these delimiters**. Just use the raw pattern, like:

```conf
^foo_(.*)\.txt$
```

### How can I avoid infinite renaming loops?

Make sure your rules are designed so that the **new file name does not match the pattern again**, or the renaming will repeat in a loop.

For example, if you want to prepend a timestamp to the filename, make sure to exclude files that already start with a timestamp. Here's a rule that does just that:
```
^(?!\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}).*:{date|Y-m-d_H-i-s}_$0
```
This rule appends the current date and time (e.g., `2025-04-10_15-30-00_`) to the beginning of the file name—but only if it doesn’t already start with a timestamp in that format.

### How can I use `:` in the pattern and replacement?

The `:` character is used as a delimiter between the pattern and replacement in the `.rename.conf` file.

If you want to **match a colon** in the file name (i.e., use it in the pattern), you need to **escape it with a backslash**, like so: `\:`.

In the **replacement** part, you can use a literal `:` without escaping.

For example this rule renames `Chapter: 01.txt` to `Chapter_01.txt`.

```
^Chapter\: (\d+)\.txt$:Chapter_$1.txt
```

### How can I use `/` in the pattern and replacement?

Use `/` directly in the pattern and replacement without escaping, as no delimiters are required.

When testing on regex101.com, change the default delimiter from `/` to another character (e.g., `#` or `~`) to include `/` literally without escaping.

## Configuration File

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

## Troubleshooting

### What should I do if a file is not renamed but I expect it to be renamed?

- Please note that the renaming process happens in a background job, so it may take some time to complete. You can check the last run of the background job under **"Administration Settings" > "Basic Settings"** to see if it's in progress or has failed.

- **Test your renaming rules** to ensure they are working as expected. For more information on testing your rules, refer to the ["How can I test the rules?"](#how-can-i-test-the-rules) FAQ entry.

- Check the **Log reader** for any entries related to the AutoRename app. This may help identify any issues or errors that occurred during the renaming process.

- If needed, **set the log level to** `debug` in Nextcloud's logging settings to trace the behavior of the AutoRename app more closely.

### Will existing files be renamed after I create or update a `.rename.conf` file?

No — **existing files are not renamed automatically** when a `.rename.conf` file is added or modified.

The AutoRename application is only triggered when:

- A file is **uploaded** into the folder  
- A file is **moved** into the folder  
- A file is **renamed** within the folder  

In these cases, AutoRename checks for the presence of a `.rename.conf` file in the target folder and applies matching rules if found.

To apply new rules to existing files, you must manually trigger the renaming by either renaming the files (e.g., adding a character) or moving them out of and back into the folder.

### Why isn’t AutoRename renaming files on my external storage?

If files are copied directly to the external storage, AutoRename can not trigger the renaming process. Instead, upload files through the Nextcloud web interface, mobile app, or desktop client.