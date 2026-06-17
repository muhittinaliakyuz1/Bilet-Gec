<?php if(!defined('ALLOWED_ACCESS')) die('Doğrudan erişim yasak'); ?>
</main>

<!-- Footer -->
<footer class="site-footer">
    <div class="container">
        <div class="footer-grid">
            <!-- Column 1: Hakkımızda -->
            <div class="footer-col">
                <h4>🎫 Bilet-Geç</h4>
                <p>
                    Türkiye'nin en güvenilir online bilet platformu. Konser, tiyatro, spor ve daha fazlası için 
                    kolayca bilet alın, unutulmaz anlar yaşayın.
                </p>
                <div class="footer-social">
                    <a href="#" aria-label="Twitter" title="Twitter">🐦</a>
                    <a href="#" aria-label="Instagram" title="Instagram">📸</a>
                    <a href="#" aria-label="Facebook" title="Facebook">👥</a>
                    <a href="#" aria-label="YouTube" title="YouTube">🎬</a>
                </div>
            </div>

            <!-- Column 2: Hızlı Linkler -->
            <div class="footer-col">
                <h4>Hızlı Linkler</h4>
                <ul>
                    <li><a href="/ilterhoca/">Ana Sayfa</a></li>
                    <li><a href="/ilterhoca/events.php">Etkinlikler</a></li>
                    <li><a href="/ilterhoca/my_tickets.php">Biletlerim</a></li>
                    <li><a href="/ilterhoca/auth/login.php">Giriş Yap</a></li>
                    <li><a href="/ilterhoca/auth/register.php">Kayıt Ol</a></li>
                </ul>
            </div>

            <!-- Column 3: Kategoriler -->
            <div class="footer-col">
                <h4>Kategoriler</h4>
                <ul>
                    <li><a href="/ilterhoca/events.php">🎵 Konser</a></li>
                    <li><a href="/ilterhoca/events.php">🎭 Tiyatro</a></li>
                    <li><a href="/ilterhoca/events.php">⚽ Spor</a></li>
                    <li><a href="/ilterhoca/events.php">🎤 Stand-up</a></li>
                    <li><a href="/ilterhoca/events.php">🎪 Festival</a></li>
                </ul>
            </div>

            <!-- Column 4: İletişim -->
            <div class="footer-col">
                <h4>İletişim</h4>
                <ul>
                    <li>📧 <a href="mailto:info@biletgec.com">info@biletgec.com</a></li>
                    <li>📞 <a href="tel:+902121234567">+90 (212) 123 45 67</a></li>
                    <li>📍 İstanbul, Türkiye</li>
                    <li>🕐 Pazartesi - Cuma: 09:00 - 18:00</li>
                </ul>
            </div>
        </div>

        <!-- Footer Bottom -->
        <div class="footer-bottom">
            <p>&copy; 2026 Bilet-Geç. Tüm hakları saklıdır.</p>
        </div>
    </div>
</footer>

<!-- Back to Top Button -->
<button class="back-to-top" aria-label="Yukarı çık" title="Yukarı çık">↑</button>

<!-- Main JavaScript -->
<script src="/ilterhoca/assets/js/app.js?v=<?= time() ?>"></script>
</body>
</html>
