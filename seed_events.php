<?php
/**
 * Seed script: Insert many unique sample events into the Bilet-Geç database.
 */

define('ALLOWED_ACCESS', true);
require_once __DIR__ . '/config/database.php';

$categories = [1, 2, 3, 4, 5, 6, 7];
$cities = ['İstanbul', 'Ankara', 'İzmir', 'Antalya', 'Bursa', 'Eskişehir', 'Adana', 'Gaziantep', 'Konya', 'Muğla'];
$venues = [
    'Şehir Kültür Merkezi',
    'Gri Salon',
    'Boğaz Sahne',
    'Tiyatro Salonu',
    'Açık Hava Sahnesi',
    'Uluslararası Kongre Merkezi',
    'Sanat Fabrikası',
    'Deniz Kenarı Tiyatro',
    'Metro Kültür Merkezi',
    'Festivalevi'
];
$organizers = [
    'Panorama Etkinlik',
    'Akustik Prodüksiyon',
    'Sahne Sanatları',
    'Kültür Ajansı',
    'Yaratıcı Atölye',
    'Ritim Organizasyon',
    'Festival Grup',
    'Seyirci Kolektifi',
    'Sahne Dostları',
    'Performans Kulübü'
];
$eventTypes = [
    'Canlı Müzik',
    'Tiyatro Oyunu',
    'Atölye Çalışması',
    'Yoga ve Zihin',
    'Şehir Turu',
    'Stand-up Gösterisi',
    'Film Gösterimi',
    'Müzik Dinletisi',
    'Sanat Performansı',
    'Söyleşi'
];
$images = [
    'assets/images/event_1.jpg',
    'assets/images/event_2.jpg',
    'assets/images/event_3.jpg',
    'assets/images/event_4.jpg',
    'assets/images/event_5.jpg',
    'assets/images/event_6.jpg',
    'assets/images/event_7.jpg',
    'assets/images/event_8.jpg',
    'assets/images/event_9.jpg',
    'assets/images/event_10.jpg'
];

echo "Başlatılıyor: Etkinlik ekleme...\n";

$insert = $pdo->prepare(
    'INSERT INTO events (category_id, title, description, short_description, venue, city, event_date, end_date, price, total_capacity, image_url, organizer, status, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
);

$start = new DateTime('2026-06-10 19:00:00');
$eventsAdded = 0;
for ($i = 1; $i <= 40; $i++) {
    $date = clone $start;
    $date->modify('+' . ($i * 2) . ' days');
    $end = clone $date;
    $end->modify('+3 hours');

    $categoryId = $categories[array_rand($categories)];
    $city = $cities[array_rand($cities)];
    $venue = $venues[array_rand($venues)];
    $organizer = $organizers[array_rand($organizers)];
    $image = $images[array_rand($images)];
    $eventType = $eventTypes[array_rand($eventTypes)];

    $title = sprintf(
        '%s %s %s',
        ['Rüya', 'Sır', 'Köprü', 'Ritim', 'Işık', 'Sahne', 'Köşe', 'Panorama', 'Tat', 'Öykü'][($i - 1) % 10],
        $eventType,
        ['Gecesi', 'Buluşması', 'Şöleni', 'Yolculuğu', 'Seyri', 'Festival', 'Karnaval', 'Dinletisi', 'Seyahati', 'Buluşması'][($i - 1) % 10]
    );
    $title = trim(preg_replace('/\s+/', ' ', $title));

    $description = sprintf(
        '%s adlı etkinlikte %s alanında uzman isimlerle bir araya geliyoruz. Etkinlik boyunca katılımcılar, %s, %s ve %s deneyimleri yaşayacak.',
        $title,
        strtolower($eventType),
        ['özel atölye çalışmaları', 'canlı performanslar', 'soru-cevap oturumları'][($i - 1) % 3],
        ['network fırsatları', 'yaratıcı etkinlikler', 'görsel sürprizler'][($i - 1) % 3],
        ['tatlı ikramlar', 'anlatımlı tur', 'interaktif sunumlar'][($i - 1) % 3]
    );

    $shortDescription = sprintf(
        '%s için hazırlanan benzersiz bir etkinlik. %s',
        $title,
        ['Keyifli bir akşam.', 'Unutulmaz anlar.', 'Sınırlı kontenjan.'][($i - 1) % 3]
    );

    $price = 75 + (($i % 7) * 25);
    $capacity = 100 + (($i % 5) * 50);

    $insert->execute([
        $categoryId,
        $title,
        $description,
        $shortDescription,
        $venue,
        $city,
        $date->format('Y-m-d H:i:s'),
        $end->format('Y-m-d H:i:s'),
        $price,
        $capacity,
        $image,
        $organizer,
        'active'
    ]);

    $eventsAdded++;
    echo "Etkinlik eklendi: $title\n";
}

echo "Toplam $eventsAdded adet etkinlik eklendi.\n";
