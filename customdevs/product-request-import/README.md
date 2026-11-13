# Importação local de pedidos

O código-fonte, o manifesto de dependências, os testes e o instalador deste
desenvolvimento são mantidos em
`/home/vianaedias/public_html/_CustomDEVs/product-request-import/`.

O instalador publica cópias de runtime em:

- `modules/items/actions/vd_product_configurator.php`
- `js/vd-product-configurator.js`
- `js/vd-product-request.css`
- `includes/vd_quote_workflow/product-configurator.php` (apenas permite anexos TXT)

O extrator usa `pdftotext`/`pdftoppm` e Tesseract localmente. Não envia ficheiros
nem texto a serviços externos. Os temporários de OCR ficam em `/tmp` e são
removidos no fim. O original só é guardado como anexo nativo quando o utilizador
guarda o pedido.

O OCR lê até seis páginas digitalizadas por PDF; documentos com texto selecionável
são extraídos sem rasterização. A extração só preenche sugestões explícitas. O
utilizador revê o rascunho e continua responsável por selecionar o cliente,
contacto, acabamentos e valores ambíguos antes de guardar.

Validação:

- `php product-request-import-test.php`
- `php product-request-import-integration-test.php`
- `php deploy-product-request-import.php`
