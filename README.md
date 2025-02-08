# AutoRename  

AutoRename is a Nextcloud app that automatically renames and organizes newly added or moved files based on user-defined rules.  

## How It Works  

- Place a `.rename.conf` file in a folder to define renaming and moving rules using regular expressions.  
- When files are uploaded or moved into that folder, they will be renamed or relocated according to the rules.  
- A background job runs every few minutes to process the files, so changes may not be immediate. 

## Features  

✅ Define renaming and moving rules with regular expressions  
✅ Automatically process newly added or moved files  
✅ Organize files into subfolders dynamically  

## Installation  

1. Install AutoRename via the Nextcloud App Store (if available) or manually place the app in the `apps-extra/` directory.  
2. Enable the app in the Nextcloud admin panel.  
3. Ensure background jobs (cron) are configured for Nextcloud.  

## Configuration  

To use AutoRename, create a `.rename.conf` file in the desired folder. The file should contain rules in the following format:  

`pattern:replacement`

 The pattern is a regular expression to match the original file name. You can learn more and test your regex patterns at [regex101.com](https://regex101.com)


### Example Rules

#### Rename a file  
Rename `Entgeltabrechnung_Januar_2022.pdf` to `2022-01_Entgeltabrechnung.pdf`:  

```
^Entgeltabrechnung_Januar_(\d{4}).pdf$:$1-01_Entgeltabrechnung.pdf
```


#### Move a file into a subfolder  
Move `Entgeltabrechnung_Januar_2022.pdf` to `2022/2022-01_Entgeltabrechnung.pdf`:  

```
^Entgeltabrechnung_Januar_(\d{4}).pdf$:$1/$1-01_Entgeltabrechnung.pdf
```

#### rename.conf.example

More examples in [.rename.conf.example](.rename.conf.example).

## Notes  

- The first matching rule in `.rename.conf` will be applied. 
- Only newly added or moved files are processed; existing files are not renamed.
- Processing occurs in a background job, so file renaming may take a few minutes.

## License  

This project is licensed under the [GNU Affero General Public License](LICENSE).  

## Contributing  

Contributions are welcome! Feel free to submit issues and pull requests.  

---

🚀 **Simplify your file management with AutoRename!**  
