# Changelog

All notable changes to this project will be documented in this file.

## [2.1.4] - 2025-05-29
- Fixed bug introduced in v2.1.3

## [2.1.3] - 2025-05-29
- Support escape sequences in the replacement string by applying `stripcslashes()`. For example, `"\x20"` in the config file is now correctly interpreted as a space (`" "`).

## [2.1.2] - 2025-05-23
- Updated trigger condition: listen to `NodeCreatedEvent` instead of `NodeWrittenEvent`, avoiding unnecessary listener execution on file modifications.

## [2.1.1] - 2025-05-19
- Improved debug logging for exifDateTimeOriginal and photoDateTime placeholders

## [2.1.0] - 2025-05-02
- Added global renaming rules for a group folder. You can now define groupfolder-wide renaming rules by placing a `rename.groupfolder.conf` at the top a a group folder.

## [2.0.0] - 2025-05-01
- Added user-wide renaming rules:   
You can now define global renaming rules for a user by placing a `.rename.user.conf` file in the user's root directory. These rules apply to full file paths and are used if no folder-specific `.rename.conf` is present.

- New `{pdfPatternMatch|/pattern/|fallback}` placeholder to extract text from the **content of PDF files** using regular expressions.

## [1.7.0] - 2025-04-17
- Added support for case transformation in filenames with new `upper(...)` and `lower(...)` syntax.

## [1.6.0] - 2025-04-14
- Improved rule parsing: patterns can now include escaped colons `\:`.

## [1.5.0] - 2025-04-10
- Added support for multiple patterns and replacements within a single rule.

## [1.4.0] - 2025-04-10
- Fixed issue #2.
- Implemented #3 to add additional placeholders `{photoDateTime}`, `{exifDateTimeOriginal}`, and `{fileModifiedAt}`.

## [1.3.0] - 2025-02-18
- Excluded the `.rename.conf` configuration file from rename rules.

## [1.2.0] - 2025-02-10
- Used placeholders to insert the current date in filenames.

## [1.1.0] - 2025-02-09
- Set user in the background job.

## [1.0.0] - 2025-02-07
- Initial release.

[2.1.4]: https://github.com/powerflo/files_autorename/releases/tag/v2.1.4
[2.1.3]: https://github.com/powerflo/files_autorename/releases/tag/v2.1.3
[2.1.2]: https://github.com/powerflo/files_autorename/releases/tag/v2.1.2
[2.1.1]: https://github.com/powerflo/files_autorename/releases/tag/v2.1.1
[2.1.0]: https://github.com/powerflo/files_autorename/releases/tag/v2.1.0
[2.0.0]: https://github.com/powerflo/files_autorename/releases/tag/v2.0.0
[1.7.0]: https://github.com/powerflo/files_autorename/releases/tag/v1.7.0
[1.6.0]: https://github.com/powerflo/files_autorename/releases/tag/v1.6.0
[1.5.0]: https://github.com/powerflo/files_autorename/releases/tag/v1.5.0
[1.4.0]: https://github.com/powerflo/files_autorename/releases/tag/v1.4.0
[1.3.0]: https://github.com/powerflo/files_autorename/releases/tag/v1.3.0
[1.2.0]: https://github.com/powerflo/files_autorename/releases/tag/v1.2.0
[1.1.0]: https://github.com/powerflo/files_autorename/releases/tag/v1.1.0
[1.0.0]: https://github.com/powerflo/files_autorename/releases/tag/v1.0.0