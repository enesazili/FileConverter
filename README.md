# File Converter

Tek dosyalık, PHP ile çalışan dosya dönüştürücü. Görsel, metin/veri, arşiv ve (sunucuda
ilgili araç kuruluysa) belge, ses, video ve PDF dönüşümlerini yapar. Form ve dönüştürme
uç noktası aynı dosyadır; dönüşümler isteğe özel geçici dizinde yapılır ve yanıt sonrasında
silinir.

- Dosya: `fileconverter.php` (tek dosya, ~260 KB)
- Çalıştığı yer: **PHP sunucusu** (istemci tarafı değil)
- Sunucu tarafı kod: var (gerçek PHP uygulaması)

> **GitHub Pages uyarısı:** GitHub Pages PHP çalıştırmaz. Bu araç Pages üzerinde
> **çalışmaz** ve orada "çalışan araç" olarak sunulmamalıdır. PHP 8.1+ çalıştıran bir
> barındırma gerekir.

## Amaç

Küçük dosyaları hızlıca dönüştürmek için kendi kendine yeten bir araç: yükleme, doğrulama,
dönüştürme ve indirme tek dosyada; veritabanı, oturum, kullanıcı hesabı veya kalıcı depolama
yok.

## Özellikler

- Yerleşik (eklenti gerektiren) dönüşümler: JPEG/PNG/WebP/GIF/BMP arası görsel dönüşümü,
  ICO üretimi, görsel → PDF, JSON/YAML/TOML/XML/CSV/Markdown/HTML/RTF metin dönüşümleri,
  ZIP/TAR/GZ oluşturma ve yeniden paketleme.
- İsteğe bağlı (sunucuda kuruluysa görünen) dönüşümler: belge (LibreOffice), ses/video
  (FFmpeg), PDF → görsel/metin (Poppler), TIFF (Imagick), SVG rasterleştirme
  (`rsvg-convert`), BZ2/XZ/7Z/RAR (7-Zip).
- Hedef listesi çalışma anında gerçek yeteneklerden türetilir; aynı biçime dönüşüm
  sunulmaz. Motor yoksa yalnızca ilgili hedefler gizlenir ve nedeni açıklanır.
- Çoklu dosya: JavaScript varsa dosya başına istek, yoksa sıralı işleme ve ZIP yanıtı.
- Durumsuz CSRF koruması, gerçek yeniden kodlama, doğrudan ikili yanıt.
- Arayüz Türkçe; açık/koyu tema; gerçek sınırlar arayüzde gösterilir.

## Gereksinimler

- PHP **8.1+** (test edilen: 8.3.36).
- Zorunlu eklentiler: `fileinfo`, `gd`, `Phar`/`PharData`, `zlib`, `dom`.
- Önerilen: `exif` (JPEG yön bilgisi), `mbstring`.
- Yazılabilir sistem geçici dizini (`sys_get_temp_dir()`).
- `upload_max_filesize` ve `post_max_size`, araç sınırlarıyla uyumlu olmalıdır; etkin sınır
  `min(php.ini, FC_*)` olarak hesaplanır ve arayüzde gösterilir.
- Harici dönüşümler için isteğe bağlı: FFmpeg/FFprobe, LibreOffice (`soffice`), Poppler
  (`pdftoppm`, `pdftotext`), Imagick, `rsvg-convert`, 7-Zip.

## Çalıştırma

Dosyayı bir klasöre koyup PHP yerleşik sunucusunu başlatın:

```powershell
php -S 127.0.0.1:8000
```

`http://127.0.0.1:8000/fileconverter.php` adresini açın. Üretimde dosyayı yalnızca bu
aracın çalışacağı bir web köküne koyun; `public/` benzeri bir yapıya veya başka proje
dosyalarına ihtiyaç duymaz. Alt dizin kurulumunda form hedefi `SCRIPT_NAME` üzerinden
hesaplandığı için çalışmaya devam eder.

Üretim için `display_errors=Off`, `expose_php=Off`, `zend.exception_ignore_args=On`
ayarlarını ve web kökü dışında bir `error_log` yolu kullanın.

## Kullanım

1. Dosya seçin (veya sürükle-bırak); birden çok dosya seçilebilir.
2. Kaynak türüne göre filtrelenmiş hedef biçimi seçin.
3. Dönüştür'e basın. Sonuç doğrudan indirilir (`Content-Disposition: attachment`).
4. Birden çok dosyada sonuçlar tek tek veya ZIP olarak iner.
5. Sayfayı yenilemek yeterlidir; sunucuda dosya kalmaz.

## Teknik ayrıntılar

- Doğrulama zinciri: `finfo` MIME → dosya imzası (magic bytes) → gerçek çözme/ayrıştırma →
  uzantı uyumu. Yalnızca uzantıya güvenilmez.
- Etkin içerik (HTML, SVG, script içeren arşiv üyeleri) güvenilmeyen girdi sayılır;
  makro, gömülü betik veya yükleme çalıştırılmaz.
- Animasyonlu GIF/WebP/APNG/AVIF reddedilir; tek kareye sessizce indirgenmez.
- Görsel dönüşümü GD ile yeniden kodlanır; EXIF/GPS/ICC metadata'sı çıktıya taşınmaz.
- Arşivlerde giriş sayısı, tek giriş boyutu, toplam açılmış boyut ve sıkıştırma oranı
  sınırları uygulanır. Sembolik/sabit bağlantı ve aygıt girişleri reddedilir; TAR
  başlıkları ham baytlar üzerinden taranır.
- Arşiv giriş adları `..`, mutlak yol, sürücü harfi ve kontrol karakterlerinden arındırılır;
  yinelenen adlar benzersizleştirilir.
- Harici komutlar kabuk dizesiyle değil, **argv dizisiyle** çalıştırılır
  (`bypass_shell`), sınırlı `cwd`, zaman aşımı, çıktı üst sınırı ve çıkış kodu doğrulaması
  vardır. Kullanıcı girdisi komut adı veya argüman parçası olamaz.
- Her istek için `sys_get_temp_dir()/fc-*` altında rastgele bir çalışma dizini oluşturulur;
  başarı, hata, zaman aşımı ve iptal durumlarında silinir.
- Yanıt dönüştürülen baytları doğrudan gönderir; `Cache-Control: no-store` ve indirme
  yanıtında CSP `sandbox` başlıkları vardır. Kalıcı indirme bağlantısı üretilmez.
- Hata yanıtları yığın izi, dosya yolu, komut satırı veya ham motor çıktısı göstermez;
  istisnalar hassas bağlam olmadan `error_log`'a yazılır.

## Sınırlar ve güvenlik

| Ayar | Varsayılan |
| --- | --- |
| `FC_MAX_FILE_BYTES` | 8 MiB |
| `FC_MAX_REQUEST_BYTES` | 24 MiB |
| `FC_MAX_FILES` | 6 |
| `FC_MAX_IMAGE_PIXELS` | 24.000.000 |
| `FC_MAX_TEXT_BYTES` | 8 MiB |
| `FC_MAX_OUTPUT_BYTES` | 64 MiB |
| `FC_PROCESS_TIMEOUT` | 30 sn |
| `FC_OFFICE_TIMEOUT` | 90 sn |
| `FC_PDF_DPI` | 150 |
| `FC_MAX_ARCHIVE_ENTRIES` | 200 |
| `FC_MAX_ARCHIVE_ENTRY_BYTES` | 32 MiB |
| `FC_MAX_ARCHIVE_TOTAL_BYTES` | 64 MiB |
| `FC_MAX_ARCHIVE_RATIO` | 200 |

- CSRF: `fc_csrf` adlı, 64 hex karakterli, `HttpOnly` ve `SameSite=Strict` çerez; oturum
  veya kullanıcı hesabı yoktur.
- 413 (`file_too_large` / `request_too_large`), 415 (`mime_mismatch`), 403
  (`csrf_failed`), 400 (`invalid_target`) durumları ayrı hata kodlarıyla döner.
- Hiçbir dosya kalıcı saklanmaz; veritabanı, IP kaydı, dosya adı kaydı veya indirme
  geçmişi yoktur. Standart web sunucusu erişim günlükleri uygulama dışındadır.
- PDF dönüşümlerinde yalnızca ilk sayfa işlenir; PDF → TXT sayfa düzenini korumaz.
- Harici motorların kendi lisans ve güvenlik koşulları geçerlidir; bu depo onları
  içermez ve kurmaz.

## Test edilenler

2026-10-28, PHP 8.3.36 ve Edge/Chromium 156 ile; portfolyo dizini dışındaki geçici bir
klasörden servis edilerek:

- `php -l` sözdizimi denetimi temiz.
- Sayfa `HTTP 200`, tek `<h1>`, harici `<script src>`/`<link>` yok, konsol hatası yok.
- Gerçek dönüşüm: `sample.png` → `webp`, `HTTP 200`, 402 bayt, `RIFF`/`WEBP` imzası,
  `Content-Disposition: attachment; filename="sample.webp"`.
- CSRF belirteci olmadan `403 csrf_failed`.
- Geçersiz hedef (`exe`) ile `400 invalid_target`.
- İçerik/uzantı uyuşmazlığında (`image/png` bildirilen JPEG baytları) `415 mime_mismatch`.
- Dönüşüm sonrası sistem geçici dizininde `fc-*` **dizin** kalmadı.
- 1440 px ve 390 px genişlikte yatay taşma yok.

Ek olarak portfolyo deposundaki test paketleri bu dosyanın mantığını kapsar
(`tests/file_converter_test.php`, `file_converter_hardening_test.php`,
`file_converter_http_test.php`, `file_converter_limits_test.php`); sonuçlar
`PROJECT_STATUS.md` içinde tutulur.

## Test edilmeyenler ve bilinen sınırlar

- FFmpeg, LibreOffice, Poppler, Imagick, `rsvg-convert` ve 7-Zip bu ortamda kurulu
  değildi; bu motorlara bağlı dönüşümler **fiilen çalıştırılmadı**, yalnızca motor yokken
  gizlendikleri doğrulandı.
- Firefox/Safari istemci testi ve gerçek mobil cihaz testi yapılmadı.
- AVIF desteği sunucudaki GD derlemesine bağlıdır.
- RAR yalnızca okunabilir; RAR oluşturulmaz.
- Çok büyük dosyalarda (php.ini sınırına yakın) bellek ve süre davranışı ölçülmedi.
- E-posta/antivirüs tarayıcılarının indirilen dosyalara müdahalesi test edilmedi.
