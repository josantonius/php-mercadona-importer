# CHANGELOG

## [v1.0.2](https://github.com/josantonius/php-mercadona-importer/releases/tag/v1.0.2) (2023-06-11)

### Added

- The `slug` field was added to the basic product information in the `product_mapping.json` file.

### Updated

- Documentation was updated.

## [v1.0.1](https://github.com/josantonius/php-mercadona-importer/releases/tag/v1.0.1) (2023-01-31)

### Changed

- Renamed the product mapping file from `ean_id_mapping.json` to `product_mapping.json`.
- The output directory was renamed from `products` to `data`.
- The `product mapping` file is now saved in the root of the output directory.
- The `product mapping` file now includes product name, ID, EAN and the warehouses.
- Now `includeFullProduct` option also adds the full details on products for which they have not been added.
- Messages were modified.

### Fixed

- The counter of reviewed products was fixed.
- Several errors were fixed to avoid extraordinary revisions of products when import was paused.

### Refactored

- The option to import without specifying a warehouse was discarded, `svq1` will be used by default.
- Changed the `reimportFullProduct` option from true to false in the `mercadona-importer.php` file.

### Updated

- Documentation was updated.

## [v1.0.0](https://github.com/josantonius/php-mercadona-importer/releases/tag/v1.0.0) (2023-01-25)

- Initial release.
