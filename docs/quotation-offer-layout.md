# Quotation download layout

Commercial and technical offers use the US Letter layout measured from the supplied QTN-COR-321-OXY-26 PDFs. Riyada is intentionally omitted. The original header, footer, ABB badge and stamp are bundled as `resources/quotation-assets/offer-*` and do not change other document types.

The PDF renderer needs **licensed Calibri regular and bold** (`calibri.ttf` and `calibrib.ttf`) on the server to reproduce the sample typography. Set `QUOTATION_FONT_DIRECTORY` to their installed directory. Windows defaults to `C:/Windows/Fonts`; Linux defaults to `/usr/share/fonts/truetype/msttcorefonts`. Without those files, PDFs remain usable using DejaVu Sans, but their appearance and pagination will differ. The fonts themselves are not redistributed in this repository. Word documents also specify Calibri.

Prices and totals remain dynamic: commercial offers keep applicable VAT, charge and discount summary rows, while technical offers omit financial columns and payment terms. Item codes appear inside the description if not already present in the description text. Dates, reference numbers, item numbering, contacts and commercial terms always come from the saved quotation; sample values are not hardcoded into templates.

Downloads with an older or missing `.layout` marker regenerate from their saved snapshot without editing that snapshot or creating a revision. New assets, PHP services, configuration and Blade templates must all be deployed together, and compiled views/configuration refreshed using the normal deployment process.

For an isolated visual preview, run `php scripts/preview-quotation-layout.php /absolute/path/to/snapshot.json preview-name`. It writes PDF and Word files only below `storage/app/quotation-format-preview`; it does not update quotation records. Check both PDF page sizes and all pages after converting Word files in the target office application.
