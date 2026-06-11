<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

start_secure_session();

// Expire old reservations on page load
expire_old_reservations($pdo);

// Fetch stats
$stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE status = ?");
$stmt->execute(['active']);
$total_events = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM users");
$stmt->execute();
$total_users = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM tickets");
$stmt->execute();
$total_tickets_sold = (int)$stmt->fetchColumn();

// Fetch categories
$stmt = $pdo->prepare("SELECT * FROM categories ORDER BY name ASC");
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch active events with category info
$stmt = $pdo->prepare("
    SELECT e.*, c.name AS category_name, c.icon AS category_icon, c.slug AS category_slug
    FROM events e
    LEFT JOIN categories c ON e.category_id = c.id
    WHERE e.status = 'active' AND e.event_date >= NOW()
    ORDER BY e.event_date ASC
");
$stmt->execute();
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Collect unique cities for filter dropdown
$cities = [];
foreach ($events as $event) {
    $city = $event['city'];
    if (!empty($city) && !in_array($city, $cities)) {
        $cities[] = $city;
    }
}
sort($cities);

$page_title = "Ana Sayfa";
$current_page = "home";

require_once __DIR__ . '/includes/header.php';
?>

<!-- Hero Section -->
<section class="hero-section">
    <div class="hero-content">
        <h1 class="hero-title">Yerel Etkinlikleri <span class="gradient-text">Keşfet</span></h1>
        <p class="hero-subtitle">Tiyatro, konser, atölye ve daha fazlası. Biletini hemen al!</p>
        <div class="hero-search">
            <span class="search-icon">🔍</span>
            <input type="text" id="hero-search-input" class="search-input" placeholder="Etkinlik, mekan veya şehir ara...">
        </div>
        <div class="hero-stats">
            <div class="stat-item">
                <span class="stat-number"><?php echo $total_events; ?>+</span>
                <span class="stat-label">Etkinlik</span>
            </div>
            <div class="stat-item">
                <span class="stat-number"><?php echo $total_users; ?>+</span>
                <span class="stat-label">Kullanıcı</span>
            </div>
            <div class="stat-item">
                <span class="stat-number"><?php echo $total_tickets_sold; ?>+</span>
                <span class="stat-label">Bilet Satıldı</span>
            </div>
        </div>
    </div>
</section>

<!-- Category Filter Bar -->
<section class="category-filter-section">
    <div class="container">
        <div class="category-filter-bar">
            <button class="category-btn active" data-category="all">
                <span class="category-btn-icon">🎯</span> Tümü
            </button>
            <?php foreach ($categories as $cat): ?>
            <button class="category-btn" data-category="<?php echo (int)$cat['id']; ?>">
                <span class="category-btn-icon"><?php echo htmlspecialchars($cat['icon']); ?></span>
                <?php echo htmlspecialchars($cat['name']); ?>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Events Grid Section -->
<section class="events-section" id="events">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">Yaklaşan Etkinlikler</h2>
            <div class="filter-controls">
                <select id="city-filter" class="filter-select glass-input">
                    <option value="">Tüm Şehirler</option>
                    <?php foreach ($cities as $city): ?>
                    <option value="<?php echo htmlspecialchars($city); ?>"><?php echo htmlspecialchars($city); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="date-filter" class="filter-select glass-input">
                    <option value="">Tüm Tarihler</option>
                    <option value="today">Bugün</option>
                    <option value="week">Bu Hafta</option>
                    <option value="month">Bu Ay</option>
                </select>
                <select id="sort-filter" class="filter-select glass-input">
                    <option value="date-asc">Tarih (Yakın)</option>
                    <option value="date-desc">Tarih (Uzak)</option>
                    <option value="price-asc">Fiyat (Düşük)</option>
                    <option value="price-desc">Fiyat (Yüksek)</option>
                </select>
            </div>
        </div>

        <div class="events-grid" id="events-grid">
            <?php if (empty($events)): ?>
            <div class="no-results glass-card">
                <p>🎭 Henüz etkinlik bulunmuyor.</p>
            </div>
            <?php else: ?>
                <?php foreach ($events as $event):
                    $remaining = get_remaining_capacity($pdo, $event['id']);
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
                <div class="event-card" data-category="<?php echo (int)$event['category_id']; ?>" data-city="<?php echo htmlspecialchars($event['city']); ?>" data-date="<?php echo $event_date_iso; ?>" data-price="<?php echo (float)$event['price']; ?>">
                    <div class="event-card-image">
                        <img src="<?php echo htmlspecialchars($event['image_url']); ?>" alt="<?php echo htmlspecialchars($event['title']); ?>" loading="lazy">
                        <span class="event-card-category"><?php echo htmlspecialchars($event['category_icon']); ?> <?php echo htmlspecialchars($event['category_name']); ?></span>
                        <span class="event-card-date"><?php echo $formatted_date; ?></span>
                    </div>
                    <div class="event-card-body">
                        <h3 class="event-card-title"><?php echo htmlspecialchars($event['title']); ?></h3>
                        <p class="event-card-desc"><?php echo htmlspecialchars($event['short_description']); ?></p>
                        <div class="event-card-meta">
                            <span>📍 <?php echo htmlspecialchars($event['venue']); ?>, <?php echo htmlspecialchars($event['city']); ?></span>
                            <span>👤 <?php echo htmlspecialchars($event['organizer']); ?></span>
                        </div>
                        <div class="event-card-footer">
                            <span class="event-card-price">₺<?php echo number_format((float)$event['price'], 2, ',', '.'); ?></span>
                            <span class="event-card-remaining remaining-<?php echo $color; ?>">
                                <?php echo $remaining_text; ?>
                            </span>
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

<!-- Newsletter Section -->
<section class="newsletter-section">
    <div class="container">
        <div class="newsletter-card glass-card">
            <div class="newsletter-content">
                <h2 class="newsletter-title">📬 Etkinliklerden haberdar ol</h2>
                <p class="newsletter-desc">Yeni etkinlikler ve fırsatlar hakkında ilk sen bilgilendirileceksin.</p>
                <div class="newsletter-form">
                    <input type="email" class="newsletter-input glass-input" placeholder="E-posta adresinizi girin...">
                    <button class="btn btn-primary newsletter-btn">Abone Ol</button>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Category filter
    const categoryBtns = document.querySelectorAll('.category-btn');
    const eventCards = document.querySelectorAll('.event-card');

    categoryBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            categoryBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            filterEvents();
        });
    });

    // City filter
    const cityFilter = document.getElementById('city-filter');
    cityFilter.addEventListener('change', filterEvents);

    // Date filter
    const dateFilter = document.getElementById('date-filter');
    dateFilter.addEventListener('change', filterEvents);

    // Sort filter
    const sortFilter = document.getElementById('sort-filter');
    sortFilter.addEventListener('change', filterEvents);

    // Hero search
    const heroSearch = document.getElementById('hero-search-input');
    heroSearch.addEventListener('input', filterEvents);

    function filterEvents() {
        const activeCategory = document.querySelector('.category-btn.active').dataset.category;
        const selectedCity = cityFilter.value;
        const selectedDate = dateFilter.value;
        const searchTerm = heroSearch.value.toLowerCase().trim();
        const sortValue = sortFilter.value;

        let visibleCards = [];

        eventCards.forEach(card => {
            let show = true;

            // Category filter
            if (activeCategory !== 'all' && card.dataset.category !== activeCategory) {
                show = false;
            }

            // City filter
            if (selectedCity && card.dataset.city !== selectedCity) {
                show = false;
            }

            // Date filter
            if (selectedDate) {
                const cardDate = new Date(card.dataset.date);
                const today = new Date();
                today.setHours(0, 0, 0, 0);

                if (selectedDate === 'today') {
                    const tomorrow = new Date(today);
                    tomorrow.setDate(tomorrow.getDate() + 1);
                    if (cardDate < today || cardDate >= tomorrow) show = false;
                } else if (selectedDate === 'week') {
                    const weekEnd = new Date(today);
                    weekEnd.setDate(weekEnd.getDate() + 7);
                    if (cardDate < today || cardDate > weekEnd) show = false;
                } else if (selectedDate === 'month') {
                    const monthEnd = new Date(today);
                    monthEnd.setDate(monthEnd.getDate() + 30);
                    if (cardDate < today || cardDate > monthEnd) show = false;
                }
            }

            // Search filter
            if (searchTerm) {
                const title = card.querySelector('.event-card-title').textContent.toLowerCase();
                const desc = card.querySelector('.event-card-desc').textContent.toLowerCase();
                const meta = card.querySelector('.event-card-meta').textContent.toLowerCase();
                if (!title.includes(searchTerm) && !desc.includes(searchTerm) && !meta.includes(searchTerm)) {
                    show = false;
                }
            }

            card.style.display = show ? '' : 'none';
            if (show) visibleCards.push(card);
        });

        // Sort visible cards
        const grid = document.getElementById('events-grid');
        visibleCards.sort((a, b) => {
            switch (sortValue) {
                case 'date-asc':
                    return new Date(a.dataset.date) - new Date(b.dataset.date);
                case 'date-desc':
                    return new Date(b.dataset.date) - new Date(a.dataset.date);
                case 'price-asc':
                    return parseFloat(a.dataset.price) - parseFloat(b.dataset.price);
                case 'price-desc':
                    return parseFloat(b.dataset.price) - parseFloat(a.dataset.price);
                default:
                    return 0;
            }
        });

        visibleCards.forEach(card => grid.appendChild(card));
    }

    // Newsletter button (decorative)
    const newsletterBtn = document.querySelector('.newsletter-btn');
    if (newsletterBtn) {
        newsletterBtn.addEventListener('click', function() {
            const input = document.querySelector('.newsletter-input');
            if (input.value.trim() !== '') {
                input.value = '';
                newsletterBtn.textContent = '✓ Abone Olundu!';
                newsletterBtn.classList.add('btn-success');
                setTimeout(() => {
                    newsletterBtn.textContent = 'Abone Ol';
                    newsletterBtn.classList.remove('btn-success');
                }, 3000);
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
