<?php
/**
 * Bilet-Geç - Etkinlikler Sayfası
 */

define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

start_secure_session();
expire_old_reservations($pdo);

$search = trim($_GET['search'] ?? '');
$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$city = trim($_GET['city'] ?? '');
$venue = trim($_GET['venue'] ?? '');
$sort_by = trim($_GET['sort_by'] ?? 'date_asc');
$date_range = trim($_GET['date_range'] ?? '');

$allowed_sorts = ['date_asc', 'date_desc', 'price_asc', 'price_desc', 'popularity'];
if (!in_array($sort_by, $allowed_sorts, true)) {
    $sort_by = 'date_asc';
}

$filters = [
    'sort_by' => $sort_by,
    'category_id' => $category_id > 0 ? $category_id : null,
    'city' => $city !== '' ? $city : null,
    'venue' => $venue !== '' ? $venue : null,
    'search' => $search !== '' ? $search : null,
];

$today = new DateTime();
$filters['date_from'] = $today->format('Y-m-d');

if ($date_range === 'today') {
    $filters['date_to'] = $today->format('Y-m-d');
} elseif ($date_range === 'week') {
    $dateTo = clone $today;
    $dateTo->modify('+7 days');
    $filters['date_to'] = $dateTo->format('Y-m-d');
} elseif ($date_range === 'month') {
    $dateTo = clone $today;
    $dateTo->modify('+30 days');
    $filters['date_to'] = $dateTo->format('Y-m-d');
}

$events = search_events($pdo, $filters);

$stmt = $pdo->prepare("SELECT id, name, icon FROM categories ORDER BY name ASC");
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT DISTINCT city FROM events WHERE status != 'cancelled' AND event_date >= NOW() ORDER BY city ASC");
$stmt->execute();
$cities = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'city');

$stmt = $pdo->prepare("SELECT DISTINCT venue, city FROM events WHERE status != 'cancelled' AND event_date >= NOW() ORDER BY city ASC, venue ASC");
$stmt->execute();
$venues = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Etkinlikler';
$current_page = 'events';

require_once __DIR__ . '/includes/header.php';
?>

<section class="page-header-section">
    <div class="container">
        <div class="page-header-inner">
            <div>
                <h1 class="page-title">Tüm Etkinlikler</h1>
                <p class="page-subtitle">Yaklaşan etkinlikleri filtrele, tarih, şehir ve kategoriye göre sırala.</p>
            </div>
        </div>
    </div>
</section>

<section class="events-section events-page-section">
    <div class="container">
        <form method="GET" class="events-filter-form">
            <div class="filter-row">
                <input type="search" name="search" id="search-input" class="form-input glass-input" placeholder="Etkinlikleri ara..." value="<?php echo e($search); ?>">
                <select name="city" id="city-filter" class="filter-select glass-input" onchange="this.form.submit()">
                    <option value="">Tüm Şehirler</option>
                    <?php foreach ($cities as $cityOption): ?>
                        <option value="<?php echo e($cityOption); ?>" <?php echo $cityOption === $city ? 'selected' : ''; ?>><?php echo e($cityOption); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="venue" id="venue-filter" class="filter-select glass-input" <?php echo $city === '' ? 'disabled' : ''; ?> onchange="this.form.submit()">
                    <option value="">Tüm Mekanlar</option>
                    <?php foreach ($venues as $venueOption): ?>
                        <option value="<?php echo e($venueOption['venue']); ?>" data-city="<?php echo e($venueOption['city']); ?>" <?php echo $venueOption['venue'] === $venue ? 'selected' : ''; ?>><?php echo e($venueOption['venue']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="date_range" id="date-filter" class="filter-select glass-input" onchange="this.form.submit()">
                    <option value="">Tüm Tarihler</option>
                    <option value="today" <?php echo $date_range === 'today' ? 'selected' : ''; ?>>Bugün</option>
                    <option value="week" <?php echo $date_range === 'week' ? 'selected' : ''; ?>>Bu Hafta</option>
                    <option value="month" <?php echo $date_range === 'month' ? 'selected' : ''; ?>>Bu Ay</option>
                </select>
                <select name="sort_by" id="sort-filter" class="filter-select glass-input" onchange="this.form.submit()">
                    <option value="date_asc" <?php echo $sort_by === 'date_asc' ? 'selected' : ''; ?>>Tarih (Yakın)</option>
                    <option value="date_desc" <?php echo $sort_by === 'date_desc' ? 'selected' : ''; ?>>Tarih (Uzak)</option>
                    <option value="price_asc" <?php echo $sort_by === 'price_asc' ? 'selected' : ''; ?>>Fiyat (Düşük)</option>
                    <option value="price_desc" <?php echo $sort_by === 'price_desc' ? 'selected' : ''; ?>>Fiyat (Yüksek)</option>
                    <option value="popularity" <?php echo $sort_by === 'popularity' ? 'selected' : ''; ?>>Popülerlik</option>
                </select>
            </div>

            <div class="category-filter-bar">
                <button type="submit" class="category-btn <?php echo $category_id <= 0 ? 'active' : ''; ?>" data-category-filter="all" name="category_id" value="0">Tümü</button>
                <?php foreach ($categories as $cat): ?>
                    <button type="submit" class="category-btn <?php echo $category_id === (int)$cat['id'] ? 'active' : ''; ?>" data-category-filter="<?php echo (int)$cat['id']; ?>" name="category_id" value="<?php echo (int)$cat['id']; ?>">
                        <?php echo e($cat['icon']); ?> <?php echo e($cat['name']); ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </form>

        <div class="events-grid" id="events-grid">
            <?php if (empty($events)): ?>
                <div id="no-results" class="no-results glass-card">
                    <p>🎭 Aradığınız kriterlere uygun etkinlik bulunamadı.</p>
                </div>
            <?php else: ?>
                <?php foreach ($events as $event):
                    $remaining = $event['remaining_capacity'] ?? null;
                    if ($remaining === null) {
                        $remaining = get_remaining_capacity($pdo, $event['id']);
                    }
                    $total = (int)$event['total_capacity'];
                    $percent = $total > 0 ? ($remaining / $total) * 100 : 0;
                    if ($remaining <= 0) {
                        $color = 'sold-out';
                        $remaining_text = 'Tükendi';
                    } elseif ($percent <= 20) {
                        $color = 'red';
                        $remaining_text = $remaining . ' bilet kaldı';
                    } elseif ($percent <= 50) {
                        $color = 'yellow';
                        $remaining_text = $remaining . ' bilet kaldı';
                    } else {
                        $color = 'green';
                        $remaining_text = $remaining . ' bilet kaldı';
                    }
                    $event_date_obj = new DateTime($event['event_date']);
                    $formatted_date = $event_date_obj->format('d M Y, H:i');
                    $event_date_iso = $event_date_obj->format('Y-m-d');
                ?>
                <div class="event-card" data-event-card data-title="<?php echo e($event['title']); ?>" data-venue="<?php echo e($event['venue']); ?>" data-category="<?php echo (int)$event['category_id']; ?>" data-city="<?php echo e($event['city']); ?>" data-date="<?php echo e($event_date_iso); ?>" data-price="<?php echo (float)$event['price']; ?>">
                    <div class="event-card-image">
                        <img src="<?php echo e(resolve_url($event['image_url'])); ?>" alt="<?php echo e($event['title']); ?>" loading="lazy" onerror="this.src='https://placehold.co/800x400/1a1a2e/7c3aed?text=Görsel';">
                        <span class="event-card-category"><?php echo e($event['category_icon']); ?> <?php echo e($event['category_name']); ?></span>
                        <span class="event-card-date"><?php echo $formatted_date; ?></span>
                    </div>
                    <div class="event-card-body">
                        <h3 class="event-card-title"><?php echo e($event['title']); ?></h3>
                        <p class="event-card-desc"><?php echo e($event['short_description']); ?></p>
                        <div class="event-card-meta">
                            <span>📍 <?php echo e($event['venue']); ?>, <?php echo e($event['city']); ?></span>
                            <span>👤 <?php echo e($event['organizer']); ?></span>
                        </div>
                        <div class="event-card-footer">
                            <span class="event-card-price">₺<?php echo number_format((float)$event['price'], 2, ',', '.'); ?></span>
                            <span class="event-card-remaining remaining-<?php echo $color; ?>"><?php echo e($remaining_text); ?></span>
                            <?php if ($remaining > 0): ?>
                                <a href="event.php?id=<?php echo (int)$event['id']; ?>" class="btn btn-primary btn-sm">Bilet Al</a>
                            <?php else: ?>
                                <span class="btn btn-disabled btn-sm">Tükendi</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
