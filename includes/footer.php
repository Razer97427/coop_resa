</div> <!-- Fin du .content -->

<footer class="main-footer">
    <div class="footer-inner">
        <div class="footer-brand">
            <svg viewBox="0 0 24 24" width="17" height="17" fill="rgba(255,255,255,0.60)">
                <path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>
            </svg>
            TERRACOOP
            <span class="footer-sep">·</span>
            <span class="footer-brand-sub">Flotte Automobile</span>
        </div>
        <div class="footer-copy">&copy; <?php echo date('Y'); ?> TERRACOOP &mdash; Tous droits réservés</div>
    </div>
</footer>

<script>
document.addEventListener("DOMContentLoaded", function() {
    var tables = document.querySelectorAll("table");
    tables.forEach(function(table) {
        var headers = table.querySelectorAll("thead th");
        var rows = table.querySelectorAll("tbody tr");
        if (headers.length > 0) {
            rows.forEach(function(row) {
                var cells = row.querySelectorAll("td");
                cells.forEach(function(cell, index) {
                    if (headers[index]) {
                        cell.setAttribute("data-label", headers[index].innerText);
                    }
                });
            });
        }
    });
});
</script>

</body>
</html>
