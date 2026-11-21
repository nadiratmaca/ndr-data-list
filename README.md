# NDR-DataList

WordPress için **Veri Kaynakları + Listeler + Ortak Şablonlar** üçlü mimarisi üzerine kurulu, gelişmiş veri toplama, versiyonlama ve responsive görselleştirme eklentisi.

## Özellikler

- **Veri Kaynakları (`ndr_datalist_source`):**
  - Google Sheet URL, JSON / REST API URL, CSV URL, XML URL ve Dosya Yükleme (JSON / CSV / XML) desteği.
  - Şema çözümleme, alan seçimi/yeniden adlandırma, tarih damgalı versiyonlama, tek tıkla eski versiyonu yayına alma (rollback), toplu silme ve yüksek hızlı statik `data.json` üretimi.
- **Listeler (`ndr_api_source`):**
  - Merkezi Veri Kaynaklarına veya harici API URL'lerine bağlanabilme; tek veri kaynağından birden fazla farklı görünüm üretebilme.
  - Dinamik alan eşleştirme (`{{title}}` ↔ API JSON alanı).
  - Sunucu taraflı ön filtreleme kuralı (`marka=dekorama,dekorflor&desen=kareli`).
  - Liste bazlı **Arama** (tüm veride veya belirli alanlarda), **Çoklu Filtreleme** (`Dropdown`, `Checkbox`, `Radio`), **Sıralama** (A-Z / Z-A), **Görünüm Değiştirici** (Liste / Grid / Tablo), **Uygula/Temizle buton gizleme** ve **Canlı Sonuç Sayacı**.
  - Hem canlı JS hem URL parametreleri (`?ndr_q=...&ndr_f_...=...&ndr_sort=...&ndr_view=...`) ile SSR desteği.
- **Şablonlar (`ndr_api_template`):**
  - Liste, Grid (Min/Max genişlik kontrollü), Tablo ve Özel responsive şablonlar.
  - CodeMirror sözdizimi renklendirmeli ve boyutlandırılabilir HTML, CSS, JS editörleri.
  - Kontrol paneli yerleşimi (Üst, Alt, Sol/Sağ Sidebar) ve özel kontrol HTML/CSS alanları.
  - Animasyonlu, mobil dokunmatik kaydırma (touch swipe) destekli yerleşik Lightbox.
- **Ayarlar (`ndr-data-settings`):**
  - Harici API yanıtları için Transient Cache yönetimi ve tüm listelerde ortak çalışan Genel CSS / Genel JS alanları.

## Kısa Kodlar

- `[ndr_api_renderer id="LISTE_ID"]` (isteğe bağlı `filter="alan=deger"` parametresi ile)
- `[ndr_datalist_sheet id="VERI_KAYNAGI_ID"]`
- **Dosya Yöneticisi (`ndr-file-manager`):**
  - WordPress Medya Kütüphanesinden bağımsız, Ayarlar'dan belirlenen kök dizinde dosya yükleme, silme, yeniden adlandırma, inline düzenleme, toplu ZIP paketleme ve ZIP arşiv açma.
