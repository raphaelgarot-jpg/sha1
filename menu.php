<nav id="sidebar" class="sidebar">
    <div class="menu-label">
        A.S.H.E.S. MENU
        <span onclick="toggleSHA()" style="cursor:pointer; color: var(--orange); font-size:1.8rem;">&times;</span>
    </div>
    <ul>
        <li><a href="index.php"><span>🏠</span> DASHBOARD</a></li>
        <li><a href="steckdose.php"><span>🔌</span> STECKDOSEN</a></li>
        <li><a href="rolladen.php"><span>🪟</span> ROLLÄDEN</a></li>
        <li><a href="weather.php"><span>☁️</span> WETTER</a></li>
        <li><a href="heiz.php"><span>🔥</span> HEIZUNG</a></li>
        <li><a href="router.php"><span>🌐</span> NETWORK</a></li>
        <li><a href="strom.php"><span>⚡</span> STROM</a></li>
        <li><a href="task.php"><span>🧹</span> HAUSHALT</a></li>
        <li class="separator"></li>
        <li><a href="#" onclick="subscribeUser(); return false;" style="color: var(--orange); font-weight: 900;"><span>🔔</span> NOTIFICATIONS</a></li>
        <li><a href="info.php"><span>ℹ️</span> INFO</a></li>
        <li><a href="#" onclick="forcePurgeCache(); return false;" style="color: var(--red); font-weight: 900;"><span>🧹</span> VIDER CACHE S.H.A.</a></li>
    </ul>
</nav>

<div id="overlay" class="overlay" onclick="toggleSHA()"></div>