<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
redirectIfNotLoggedIn();

$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$selected_year  = isset($_GET['year'])  ? (int)$_GET['year']  : date('Y');
$report_type    = isset($_GET['type'])  ? $_GET['type']        : 'summary';

// ── Flush overdue status before running any report queries ────────────────────
$db->query("
    UPDATE invoices
    SET    payment_status = 'Overdue'
    WHERE  payment_status = 'Pending'
      AND  due_date < CURDATE()
      AND  paid_amount = 0
");

// ── All outstanding invoices (overdue + partial) across ALL months ────────────
$outstanding = $db->query("
    SELECT i.*, c.name AS client_name, c.company
    FROM   invoices i
    JOIN   clients  c ON i.client_id = c.id
    WHERE  i.payment_status IN ('Overdue', 'Partial')
    ORDER  BY i.payment_status ASC, i.due_date ASC
");

// ── Financial summary ─────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT
        COALESCE(SUM(total), 0)                                              AS total_invoiced,
        COALESCE(SUM(paid_amount), 0)                                        AS total_received,
        COALESCE(SUM(remaining_amount), 0)                                   AS remaining_balance,
        COUNT(id)                                                            AS invoice_count,
        SUM(CASE WHEN payment_status = 'Paid'    THEN 1 ELSE 0 END)         AS paid_count,
        SUM(CASE WHEN payment_status = 'Partial' THEN 1 ELSE 0 END)         AS partial_count,
        SUM(CASE WHEN payment_status = 'Pending' THEN 1 ELSE 0 END)         AS pending_count,
        SUM(CASE WHEN payment_status = 'Overdue' THEN 1 ELSE 0 END)         AS overdue_count
    FROM invoices
    WHERE MONTH(invoice_date) = ? AND YEAR(invoice_date) = ?
");
$stmt->bind_param("ii", $selected_month, $selected_year);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();

// ── Client-wise breakdown ─────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT
        c.id, c.name, c.company,
        COUNT(i.id)              AS invoice_count,
        COALESCE(SUM(i.total), 0)           AS total_invoiced,
        COALESCE(SUM(i.paid_amount), 0)     AS total_paid,
        COALESCE(SUM(i.remaining_amount),0) AS balance
    FROM clients c
    LEFT JOIN invoices i ON c.id = i.client_id
        AND MONTH(i.invoice_date) = ?
        AND YEAR(i.invoice_date)  = ?
    GROUP BY c.id
    HAVING invoice_count > 0
    ORDER BY total_invoiced DESC
");
$stmt->bind_param("ii", $selected_month, $selected_year);
$stmt->execute();
$client_breakdown = $stmt->get_result();

// ── Project-wise breakdown ────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT
        p.id,
        p.project_name,
        p.department,
        COUNT(DISTINCT ii.invoice_id)        AS invoice_count,
        COALESCE(SUM(ii.amount), 0)          AS total_invoiced,
        COALESCE(SUM(
            ii.amount / NULLIF(inv_total.items_total, 0) * inv.paid_amount
        ), 0)                                AS total_paid,
        COALESCE(SUM(
            ii.amount / NULLIF(inv_total.items_total, 0) * inv.remaining_amount
        ), 0)                                AS balance
    FROM projects p
    JOIN invoice_items ii ON ii.project_id = p.id
    JOIN invoices inv     ON inv.id = ii.invoice_id
        AND MONTH(inv.invoice_date) = ?
        AND YEAR(inv.invoice_date)  = ?
    JOIN (
        SELECT invoice_id, SUM(amount) AS items_total
        FROM   invoice_items
        GROUP  BY invoice_id
    ) inv_total ON inv_total.invoice_id = inv.id
    GROUP BY p.id
    ORDER BY total_invoiced DESC
    LIMIT 20
");
$stmt->bind_param("ii", $selected_month, $selected_year);
$stmt->execute();
$project_breakdown = $stmt->get_result();

// ── Department-wise breakdown ─────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT
        p.department,
        COUNT(DISTINCT ii.invoice_id)        AS invoice_count,
        COALESCE(SUM(ii.amount), 0)          AS total_invoiced,
        COALESCE(SUM(
            ii.amount / NULLIF(inv_total.items_total, 0) * inv.paid_amount
        ), 0)                                AS total_paid
    FROM projects p
    JOIN invoice_items ii ON ii.project_id = p.id
    JOIN invoices inv     ON inv.id = ii.invoice_id
        AND MONTH(inv.invoice_date) = ?
        AND YEAR(inv.invoice_date)  = ?
    JOIN (
        SELECT invoice_id, SUM(amount) AS items_total
        FROM   invoice_items
        GROUP  BY invoice_id
    ) inv_total ON inv_total.invoice_id = inv.id
    GROUP BY p.department
    ORDER BY total_invoiced DESC
");
$stmt->bind_param("ii", $selected_month, $selected_year);
$stmt->execute();
$department_breakdown = $stmt->get_result();

// ── Last 12 months comparison ─────────────────────────────────────────────────
$comparison = $db->query("
    SELECT
        DATE_FORMAT(invoice_date, '%Y-%m') AS month,
        SUM(total)       AS total_invoiced,
        SUM(paid_amount) AS total_received
    FROM invoices
    WHERE invoice_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(invoice_date, '%Y-%m')
    ORDER BY month DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Financial Reports</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1>Financial Reports</h1>
            <a href="export.php?month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>"
               class="btn-secondary">Export Report</a>
        </div>

        <form method="GET" class="filter-form">
            <div class="filter-row">
                <select name="month">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $selected_month == $m ? 'selected' : ''; ?>>
                        <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                    </option>
                    <?php endfor; ?>
                </select>

                <select name="year">
                    <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $selected_year == $y ? 'selected' : ''; ?>>
                        <?php echo $y; ?>
                    </option>
                    <?php endfor; ?>
                </select>

                <button type="submit">Generate Report</button>
            </div>
        </form>

        <div class="report-header">
            <h2>Financial Report for <?php echo date('F Y', mktime(0,0,0,$selected_month,1,$selected_year)); ?></h2>
        </div>

        <!-- Summary Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value">Rs.<?php echo number_format($summary['total_invoiced'], 2); ?></div>
                <div class="stat-label">Total Invoiced</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">Rs.<?php echo number_format($summary['total_received'], 2); ?></div>
                <div class="stat-label">Total Received</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">Rs.<?php echo number_format($summary['remaining_balance'], 2); ?></div>
                <div class="stat-label">Remaining Balance</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $summary['invoice_count']; ?></div>
                <div class="stat-label">Total Invoices</div>
            </div>
        </div>

        <!-- Status Breakdown -->
        <div class="breakdown-section">
            <h3>Payment Status Breakdown</h3>
            <div class="status-breakdown">
                <div class="status-item">
                    <span class="status-label status-paid">Paid:</span>
                    <span class="status-value"><?php echo $summary['paid_count']; ?> invoices</span>
                </div>
                <div class="status-item">
                    <span class="status-label status-partial">Partial:</span>
                    <span class="status-value"><?php echo $summary['partial_count']; ?> invoices</span>
                </div>
                <div class="status-item">
                    <span class="status-label status-pending">Pending:</span>
                    <span class="status-value"><?php echo $summary['pending_count']; ?> invoices</span>
                </div>
                <div class="status-item">
                    <span class="status-label status-overdue">Overdue:</span>
                    <span class="status-value"><?php echo $summary['overdue_count']; ?> invoices</span>
                </div>
            </div>
        </div>

        <!-- Client-wise Breakdown -->
        <div class="breakdown-section">
            <h3>Client-wise Breakdown</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Company</th>
                        <th>Invoices</th>
                        <th>Invoiced</th>
                        <th>Paid</th>
                        <th>Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($client_breakdown->num_rows > 0): ?>
                        <?php while ($client = $client_breakdown->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo escape($client['name']); ?></td>
                            <td><?php echo escape($client['company']); ?></td>
                            <td><?php echo $client['invoice_count']; ?></td>
                            <td>Rs.<?php echo number_format($client['total_invoiced'], 2); ?></td>
                            <td>Rs.<?php echo number_format($client['total_paid'], 2); ?></td>
                            <td>Rs.<?php echo number_format($client['balance'], 2); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center">No invoice data for this period</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Project-wise Breakdown -->
        <div class="breakdown-section">
            <h3>Project-wise Breakdown (Top 20)</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Department</th>
                        <th>Invoices</th>
                        <th>Invoiced</th>
                        <th>Paid</th>
                        <th>Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($project_breakdown->num_rows > 0): ?>
                        <?php while ($project = $project_breakdown->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo escape($project['project_name']); ?></td>
                            <td><?php echo escape($project['department']); ?></td>
                            <td><?php echo $project['invoice_count']; ?></td>
                            <td>Rs.<?php echo number_format($project['total_invoiced'], 2); ?></td>
                            <td>Rs.<?php echo number_format($project['total_paid'], 2); ?></td>
                            <td>Rs.<?php echo number_format($project['balance'], 2); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center">No project data for this period</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Department-wise Breakdown -->
        <div class="breakdown-section">
            <h3>Department-wise Breakdown</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Department</th>
                        <th>Invoices</th>
                        <th>Total Invoiced</th>
                        <th>Total Paid</th>
                        <th>Collection Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($department_breakdown->num_rows > 0): ?>
                        <?php while ($dept = $department_breakdown->fetch_assoc()):
                            $rate = $dept['total_invoiced'] > 0
                                ? ($dept['total_paid'] / $dept['total_invoiced']) * 100
                                : 0;
                        ?>
                        <tr>
                            <td><?php echo escape($dept['department']); ?></td>
                            <td><?php echo $dept['invoice_count']; ?></td>
                            <td>Rs.<?php echo number_format($dept['total_invoiced'], 2); ?></td>
                            <td>Rs.<?php echo number_format($dept['total_paid'], 2); ?></td>
                            <td><?php echo number_format($rate, 1); ?>%</td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center">No department data for this period</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>


        <!-- ── Outstanding Balances (all months) ── -->
        <?php if ($outstanding->num_rows > 0): ?>
        <div class="breakdown-section" style="border-left: 4px solid #a81e2a; padding-left: 16px;">
            <h3 style="color:#a81e2a;">
                ⚠ Outstanding Balances — All Months
                <small style="font-size:13px; font-weight:400; color:#888; margin-left:8px;">
                    (<?php echo $outstanding->num_rows; ?> invoice<?php echo $outstanding->num_rows > 1 ? 's' : ''; ?>)
                </small>
            </h3>
            <table class="data-table" id="outstanding-table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Client</th>
                        <th>Invoice Date</th>
                        <th>Due Date</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="outstanding-tbody">
                    <?php while ($ov = $outstanding->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo escape($ov['invoice_number']); ?></td>
                        <td><?php echo escape($ov['client_name']); ?><br>
                            <small style="color:#888;"><?php echo escape($ov['company']); ?></small>
                        </td>
                        <td><?php echo date('d M Y', strtotime($ov['invoice_date'])); ?></td>
                        <td><?php echo date('d M Y', strtotime($ov['due_date'])); ?></td>
                        <td>Rs.<?php echo number_format($ov['total'], 2); ?></td>
                        <td>Rs.<?php echo number_format($ov['paid_amount'], 2); ?></td>
                        <td><strong>Rs.<?php echo number_format($ov['remaining_amount'], 2); ?></strong></td>
                        <td><span class="status-<?php echo strtolower($ov['payment_status']); ?>"><?php echo $ov['payment_status']; ?></span></td>
                        <td><a href="../invoices/view.php?id=<?php echo $ov['id']; ?>">View</a></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight:700; background:#fff5f5;">
                        <?php
                            $totals = $db->query("
                                SELECT COALESCE(SUM(total),0)            AS grand_total,
                                       COALESCE(SUM(paid_amount),0)      AS grand_paid,
                                       COALESCE(SUM(remaining_amount),0) AS grand_balance
                                FROM   invoices
                                WHERE  payment_status IN ('Overdue','Partial')
                            ")->fetch_assoc();
                        ?>
                        <td colspan="4" style="text-align:right;">Totals:</td>
                        <td>Rs.<?php echo number_format($totals['grand_total'], 2); ?></td>
                        <td>Rs.<?php echo number_format($totals['grand_paid'], 2); ?></td>
                        <td>Rs.<?php echo number_format($totals['grand_balance'], 2); ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>

            <!-- Pagination controls -->
            <div id="outstanding-pagination" style="display:flex; align-items:center; justify-content:space-between; margin-top:12px; flex-wrap:wrap; gap:8px;">
                <div id="outstanding-page-info" style="font-size:13px; color:#666;"></div>
                <div id="outstanding-page-buttons" style="display:flex; gap:4px; flex-wrap:wrap;"></div>
            </div>
        </div>

        <script>
        (function () {
            const ROWS_PER_PAGE = 10;
            const tbody   = document.getElementById('outstanding-tbody');
            const info    = document.getElementById('outstanding-page-info');
            const buttons = document.getElementById('outstanding-page-buttons');
            const rows    = Array.from(tbody.querySelectorAll('tr'));
            const total   = rows.length;

            // Hide pagination entirely if 10 or fewer rows
            if (total <= ROWS_PER_PAGE) return;

            const totalPages = Math.ceil(total / ROWS_PER_PAGE);
            let currentPage  = 1;

            function showPage(page) {
                currentPage = page;
                const start = (page - 1) * ROWS_PER_PAGE;
                const end   = start + ROWS_PER_PAGE;

                rows.forEach((row, i) => {
                    row.style.display = (i >= start && i < end) ? '' : 'none';
                });

                // Update info text
                info.textContent = `Showing ${start + 1}–${Math.min(end, total)} of ${total} invoices`;

                // Rebuild page buttons
                buttons.innerHTML = '';

                // Prev
                buttons.appendChild(makeBtn('← Prev', page === 1, () => showPage(page - 1)));

                // Numbered pages with ellipsis
                pageRange(page, totalPages).forEach(p => {
                    if (p === '…') {
                        const el = document.createElement('span');
                        el.textContent = '…';
                        el.style.cssText = 'padding:4px 8px; color:#888; line-height:1;';
                        buttons.appendChild(el);
                    } else {
                        const btn = makeBtn(p, false, () => showPage(p));
                        if (p === page) {
                            btn.style.background  = '#a81e2a';
                            btn.style.color       = '#fff';
                            btn.style.borderColor = '#a81e2a';
                            btn.style.fontWeight  = '700';
                        }
                        buttons.appendChild(btn);
                    }
                });

                // Next
                buttons.appendChild(makeBtn('Next →', page === totalPages, () => showPage(page + 1)));
            }

            function makeBtn(label, disabled, onClick) {
                const btn = document.createElement('button');
                btn.textContent = label;
                btn.disabled    = disabled;
                btn.style.cssText = `
                    padding: 4px 10px;
                    border: 1px solid #d0d0d0;
                    background: #fff;
                    border-radius: 4px;
                    cursor: ${disabled ? 'not-allowed' : 'pointer'};
                    font-size: 13px;
                    color: ${disabled ? '#bbb' : '#333'};
                    line-height: 1.4;
                `;
                if (!disabled) btn.addEventListener('click', onClick);
                return btn;
            }

            // Returns page numbers + '…' gaps; always shows first, last, current ±1
            function pageRange(current, total) {
                const pages = new Set(
                    [1, total, current, current - 1, current + 1]
                    .filter(p => p >= 1 && p <= total)
                );
                const sorted = [...pages].sort((a, b) => a - b);
                const result = [];
                let prev = null;
                for (const p of sorted) {
                    if (prev !== null && p - prev > 1) result.push('…');
                    result.push(p);
                    prev = p;
                }
                return result;
            }

            showPage(1);
        })();
        </script>
        <?php endif; ?>

        <!-- Monthly Comparison -->
        <div class="breakdown-section">
            <h3>Last 12 Months Comparison</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Total Invoiced</th>
                        <th>Total Received</th>
                        <th>Collection Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($comp = $comparison->fetch_assoc()):
                        $rate = $comp['total_invoiced'] > 0
                            ? ($comp['total_received'] / $comp['total_invoiced']) * 100
                            : 0;
                    ?>
                    <tr>
                        <td><?php echo date('F Y', strtotime($comp['month'] . '-01')); ?></td>
                        <td>Rs.<?php echo number_format($comp['total_invoiced'], 2); ?></td>
                        <td>Rs.<?php echo number_format($comp['total_received'], 2); ?></td>
                        <td><?php echo number_format($rate, 1); ?>%</td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

    </div>
</body>
</html>