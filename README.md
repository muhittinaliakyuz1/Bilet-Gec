# Bilet-Geç: Web Tabanlı Etkinlik Bilet Yönetim Sistemi

[cite_start]**Bilet-Geç**, etkinlik, festival, tiyatro ve spor müsabakaları için geliştirilmiş, yüksek trafikli anlarda veri tutarlılığını garanti altına alan, güvenli ve modüler bir web tabanlı bilet yönetim sistemidir[cite: 4, 26, 27].

## 🚀 Proje Hakkında
[cite_start]Bu proje, biletleme altyapılarında sıkça karşılaşılan "çift satma" (overbooking) ve adil kota yönetimi sorunlarını çözmek amacıyla geliştirilmiştir[cite: 29]. [cite_start]Kullanıcı merkezli modern bir deneyim sunarken, arka planda güvenliği ve veritabanı tutarlılığını ön planda tutan 3 katmanlı bir mimari kullanılmıştır[cite: 53, 54].

## 🛠 Kullanılan Teknolojiler

### Önyüz (Front-End)
* [cite_start]**HTML5 & CSS3:** Modern, responsive tasarım, değişkenler (Design Tokens) kullanılarak oluşturulan premium koyu tema (Dark Mode)[cite: 42, 44].
* [cite_start]**JavaScript (ES6+):** Fetch API ile asenkron işlemler, dinamik sepet sayaçları ve kullanıcı bildirimleri[cite: 45].
* [cite_start]**Diğer:** Font Awesome 6 & Google Fonts (Outfit)[cite: 46].

### Arkayüz (Back-End) & Veri
* [cite_start]**PHP 8.x:** Nesne yönelimli, modüler iş mantığı ve oturum yönetimi[cite: 48].
* [cite_start]**MySQL & PDO:** SQL enjeksiyonlarına karşı korumalı, güvenli veritabanı katmanı[cite: 49].

## 🔒 Güvenlik & Mimari
* [cite_start]**Veritabanı Güvenliği:** PDO (PHP Data Objects) ve `prepared statements` kullanımı[cite: 13, 49].
* [cite_start]**CSRF Koruması:** Token tabanlı savunma mekanizması[cite: 14, 56].
* [cite_start]**Race Condition Çözümü:** `SELECT ... FOR UPDATE` kilitleme mekanizması ve veritabanı `TRANSACTION` süreçleri ile veri bütünlüğünün korunması[cite: 91].
* [cite_start]**Üç Katmanlı Mimari:** Sunum, iş mantığı ve veritabanı katmanlarının ayrıştırılması[cite: 53, 54].

## ⚙️ Öne Çıkan Özellikler
* [cite_start]**Akıllı Rezervasyon:** Koltuk seçildiğinde 15 dakikalık otomatik kilit süresi[cite: 56].
* [cite_start]**Sadakat Sistemi:** 1 TL = 1 Puan prensibiyle çalışan puan sistemi ve kupon yönetimi[cite: 56].
* [cite_start]**Admin Dashboard:** Toplam hasılat takibi, bilet grafik raporları ve iade yönetimi[cite: 105].
* [cite_start]**Responsive Tasarım:** Mobil tarayıcılarla tam uyumluluk[cite: 37].

## 📊 Veritabanı Şeması (E-R Özeti)
[cite_start]Sistemde veri bütünlüğünü korumak adına `Foreign Key` ve `CASCADE` silme kuralları tanımlanmıştır[cite: 60].
* [cite_start]**users:** Kullanıcı bilgileri, rol ve sadakat puanları[cite: 63].
* [cite_start]**events:** Etkinlik detayları ve anlık kapasite takibi[cite: 65].
* [cite_start]**reservations:** 15 dakikalık kilitli rezervasyon kayıtları[cite: 67].
* [cite_start]**tickets:** Satın alma onayları ve durum takibi[cite: 69].

---

[cite_start]*Bu proje, Göztepe Mesleki ve Teknik Anadolu Lisesi "Web Tabanlı Uygulama" ve "Seçmeli Yazılım Projesi" dersleri kapsamında Muhittin Ali Akyüz tarafından geliştirilmiştir[cite: 1, 5, 11].*
