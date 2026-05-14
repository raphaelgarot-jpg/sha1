<nav id="sidebar" class="sidebar">
    <div class="menu-label">
        S.H.A. MENU
        <span onclick="toggleSHA()" style="cursor:pointer; color:#ff9800; font-size:1.8rem;">&times;</span>
    </div>
    <ul>
        <li><a href="index.php"><span>🏠</span> DASHBOARD</a></li>
        <li><a href="steckdose.php"><span>🔌</span> STECKDOSEN</a></li>
        <li><a href="rolladen.php"><span>🪟</span> ROLLÄDEN</a></li>
        <li><a href="weather.php"><span>☁️</span> WETTER</a></li>
        <li><a href="heiz.php"><span>🔥</span> HEIZUNG</a></li>
        <li><a href="router.php"><span>🌐</span> NETWORK</a></li>
        <li><a href="strom.php"><span>⚡</span> STROM</a></li>
        <li class="separator"></li>
        <li><a href="#" onclick="subscribeUser(); return false;" style="color: #ff9800; font-weight: 900;"><span>🔔</span> NOTIFICATIONS</a></li>
        <li><a href="info.php"><span>ℹ️</span> INFO</a></li>
    </ul>
</nav>

<div id="overlay" class="overlay" onclick="toggleSHA()"></div>
