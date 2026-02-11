<?php
// Admin Footer Component
?>
<footer class="admin-footer">
    <div class="footer-content">
        <div class="footer-grid">
            <div class="footer-section">
                <h4><i class="fas fa-info-circle"></i> Quick Links</h4>
                <ul>
                    <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="system_settings.php"><i class="fas fa-cogs"></i> System Settings</a></li>
                    <li><a href="backup.php"><i class="fas fa-database"></i> Backup & Restore</a></li>
                    <li><a href="logs.php"><i class="fas fa-file-alt"></i> System Logs</a></li>
                </ul>
            </div>
            
            <div class="footer-section">
                <h4><i class="fas fa-chart-line"></i> Analytics</h4>
                <ul>
                    <li><a href="analytics.php"><i class="fas fa-chart-bar"></i> Overview</a></li>
                    <li><a href="reports.php"><i class="fas fa-file-excel"></i> Reports</a></li>
                    <li><a href="export_data.php"><i class="fas fa-download"></i> Export Data</a></li>
                    <li><a href="statistics.php"><i class="fas fa-chart-pie"></i> Statistics</a></li>
                </ul>
            </div>
            
            <div class="footer-section">
                <h4><i class="fas fa-tools"></i> Tools</h4>
                <ul>
                    <li><a href="maintenance.php"><i class="fas fa-wrench"></i> Maintenance</a></li>
                    <li><a href="cache.php"><i class="fas fa-bolt"></i> Clear Cache</a></li>
                    <li><a href="email_templates.php"><i class="fas fa-envelope"></i> Email Templates</a></li>
                    <li><a href="api_settings.php"><i class="fas fa-code"></i> API Settings</a></li>
                </ul>
            </div>
            
            <div class="footer-section">
                <h4><i class="fas fa-shield-alt"></i> Security</h4>
                <ul>
                    <li><a href="security_logs.php"><i class="fas fa-user-shield"></i> Security Logs</a></li>
                    <li><a href="permissions.php"><i class="fas fa-lock"></i> Permissions</a></li>
                    <li><a href="audit_trail.php"><i class="fas fa-history"></i> Audit Trail</a></li>
                    <li><a href="security_settings.php"><i class="fas fa-cog"></i> Security Settings</a></li>
                </ul>
            </div>
        </div>
        
        <div class="footer-middle">
            <div class="system-info">
                <div class="info-item">
                    <i class="fas fa-server"></i>
                    <div>
                        <small>Server</small>
                        <strong><?php echo $_SERVER['SERVER_SOFTWARE']; ?></strong>
                    </div>
                </div>
                <div class="info-item">
                    <i class="fas fa-database"></i>
                    <div>
                        <small>Database</small>
                        <strong>MySQL</strong>
                    </div>
                </div>
                <div class="info-item">
                    <i class="fas fa-code"></i>
                    <div>
                        <small>PHP Version</small>
                        <strong><?php echo phpversion(); ?></strong>
                    </div>
                </div>
                <div class="info-item">
                    <i class="fas fa-memory"></i>
                    <div>
                        <small>Memory</small>
                        <strong><?php echo round(memory_get_usage(true)/1048576, 2); ?> MB</strong>
                    </div>
                </div>
            </div>
            
            <div class="quick-stats">
                <div class="stat-item">
                    <div class="stat-icon online"></div>
                    <span>System Status: <strong>Online</strong></span>
                </div>
                <div class="stat-item">
                    <i class="fas fa-users"></i>
                    <span>Active Users: <strong id="footerActiveUsers">0</strong></span>
                </div>
                <div class="stat-item">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Today's Orders: <strong id="footerTodayOrders">0</strong></span>
                </div>
                <div class="stat-item">
                    <i class="fas fa-clock"></i>
                    <span>Uptime: <strong id="systemUptime">100%</strong></span>
                </div>
            </div>
        </div>
        
        <div class="footer-bottom">
            <div class="copyright">
                <p>
                    <i class="fas fa-copyright"></i> <?php echo date('Y'); ?> 
                    <?php echo SITE_NAME; ?> Admin Panel. 
                    Version 2.1.0
                </p>
                <p class="last-update">
                    <i class="fas fa-sync"></i> Last Updated: 
                    <span id="lastUpdateTime"><?php echo date('Y-m-d H:i:s'); ?></span>
                </p>
            </div>
            
            <div class="footer-actions">
                <button class="btn-footer-action" onclick="refreshPage()">
                    <i class="fas fa-redo"></i> Refresh
                </button>
                <button class="btn-footer-action" onclick="showSystemInfo()">
                    <i class="fas fa-info-circle"></i> System Info
                </button>
                <button class="btn-footer-action" onclick="clearConsole()">
                    <i class="fas fa-terminal"></i> Clear Console
                </button>
                <button class="btn-footer-action btn-support" onclick="openSupport()">
                    <i class="fas fa-headset"></i> Support
                </button>
            </div>
        </div>
    </div>
</footer>

<!-- Support Chat Widget -->
<div class="support-chat" id="supportChat">
    <div class="chat-header">
        <h5><i class="fas fa-headset"></i> Support Chat</h5>
        <button class="chat-close" onclick="toggleSupportChat()">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div class="chat-messages" id="chatMessages">
        <!-- Chat messages will appear here -->
    </div>
    <div class="chat-input">
        <input type="text" placeholder="Type your message..." id="chatInput">
        <button onclick="sendChatMessage()">
            <i class="fas fa-paper-plane"></i>
        </button>
    </div>
</div>

<!-- Support Chat Toggle Button -->
<button class="chat-toggle-btn" onclick="toggleSupportChat()">
    <i class="fas fa-comments"></i>
</button>

<!-- System Info Modal -->
<div class="modal" id="systemInfoModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-info-circle"></i> System Information</h3>
            <button onclick="closeSystemInfo()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <div class="system-details">
                <div class="detail-row">
                    <span>PHP Version:</span>
                    <strong><?php echo phpversion(); ?></strong>
                </div>
                <div class="detail-row">
                    <span>MySQL Version:</span>
                    <strong id="mysqlVersion">Loading...</strong>
                </div>
                <div class="detail-row">
                    <span>Server Software:</span>
                    <strong><?php echo $_SERVER['SERVER_SOFTWARE']; ?></strong>
                </div>
                <div class="detail-row">
                    <span>Memory Limit:</span>
                    <strong><?php echo ini_get('memory_limit'); ?></strong>
                </div>
                <div class="detail-row">
                    <span>Max Execution Time:</span>
                    <strong><?php echo ini_get('max_execution_time'); ?>s</strong>
                </div>
                <div class="detail-row">
                    <span>Upload Max Filesize:</span>
                    <strong><?php echo ini_get('upload_max_filesize'); ?></strong>
                </div>
                <div class="detail-row">
                    <span>Database Size:</span>
                    <strong id="dbSize">Loading...</strong>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" onclick="copySystemInfo()">
                <i class="fas fa-copy"></i> Copy Info
            </button>
            <button class="btn btn-secondary" onclick="closeSystemInfo()">
                Close
            </button>
        </div>
    </div>
</div>

<script>
    // Load system stats for footer
    async function loadFooterStats() {
        try {
            const response = await fetch('api/stats.php?type=footer');
            const data = await response.json();
            
            document.getElementById('footerActiveUsers').textContent = data.active_users || 0;
            document.getElementById('footerTodayOrders').textContent = data.today_orders || 0;
            document.getElementById('systemUptime').textContent = data.uptime || '100%';
            
            // Update last update time
            document.getElementById('lastUpdateTime').textContent = new Date().toLocaleString();
        } catch (error) {
            console.error('Error loading footer stats:', error);
        }
    }
    
    // Load MySQL version and DB size
    async function loadSystemDetails() {
        try {
            const response = await fetch('api/system.php');
            const data = await response.json();
            
            document.getElementById('mysqlVersion').textContent = data.mysql_version || 'Unknown';
            document.getElementById('dbSize').textContent = data.db_size || 'Unknown';
        } catch (error) {
            console.error('Error loading system details:', error);
        }
    }
    
    // Toggle support chat
    function toggleSupportChat() {
        const chat = document.getElementById('supportChat');
        chat.classList.toggle('open');
    }
    
    // Send chat message
    function sendChatMessage() {
        const input = document.getElementById('chatInput');
        const message = input.value.trim();
        
        if (message) {
            const messagesDiv = document.getElementById('chatMessages');
            const messageElement = document.createElement('div');
            messageElement.className = 'message user-message';
            messageElement.innerHTML = `
                <div class="message-content">
                    <p>${escapeHtml(message)}</p>
                    <small>Just now</small>
                </div>
            `;
            messagesDiv.appendChild(messageElement);
            input.value = '';
            
            // Scroll to bottom
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
            
            // Simulate response
            setTimeout(() => {
                const responseElement = document.createElement('div');
                responseElement.className = 'message support-message';
                responseElement.innerHTML = `
                    <div class="message-content">
                        <p>Thank you for your message. Our support team will get back to you shortly.</p>
                        <small>Support Bot</small>
                    </div>
                `;
                messagesDiv.appendChild(responseElement);
                messagesDiv.scrollTop = messagesDiv.scrollHeight;
            }, 1000);
        }
    }
    
    // Show system info modal
    function showSystemInfo() {
        loadSystemDetails();
        document.getElementById('systemInfoModal').style.display = 'block';
        document.body.style.overflow = 'hidden';
    }
    
    // Close system info modal
    function closeSystemInfo() {
        document.getElementById('systemInfoModal').style.display = 'none';
        document.body.style.overflow = 'auto';
    }
    
    // Copy system info to clipboard
    function copySystemInfo() {
        const details = document.querySelector('.system-details').innerText;
        navigator.clipboard.writeText(details).then(() => {
            showNotification('System info copied to clipboard!', 'success');
        });
    }
    
    // Refresh page
    function refreshPage() {
        window.location.reload();
    }
    
    // Clear console
    function clearConsole() {
        console.clear();
        showNotification('Console cleared', 'info');
    }
    
    // Open support
    function openSupport() {
        window.open('mailto:support@foodorder.com', '_blank');
    }
    
    // Escape HTML to prevent XSS
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Initialize footer
    document.addEventListener('DOMContentLoaded', function() {
        loadFooterStats();
        
        // Auto-refresh stats every 30 seconds
        setInterval(loadFooterStats, 30000);
        
        // Load system details when modal opens
        document.getElementById('systemInfoModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeSystemInfo();
            }
        });
        
        // Chat input enter key support
        document.getElementById('chatInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                sendChatMessage();
            }
        });
    });
</script>

<style>
    .admin-footer {
        background: linear-gradient(135deg, #2d3748 0%, #4a5568 100%);
        color: #f8f9fa;
        margin-top: auto;
        border-top: 1px solid #4a5568;
    }
    
    .footer-content {
        padding: 2rem;
    }
    
    .footer-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 2rem;
        margin-bottom: 2rem;
    }
    
    .footer-section h4 {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1rem;
        font-size: 1rem;
        color: #e2e8f0;
    }
    
    .footer-section ul {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .footer-section li {
        margin-bottom: 0.5rem;
    }
    
    .footer-section a {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        color: #cbd5e0;
        text-decoration: none;
        padding: 0.5rem 0;
        transition: color 0.3s;
        font-size: 0.9rem;
    }
    
    .footer-section a:hover {
        color: white;
        padding-left: 0.5rem;
    }
    
    .footer-section i {
        width: 20px;
        text-align: center;
    }
    
    .footer-middle {
        background: rgba(0,0,0,0.2);
        border-radius: 8px;
        padding: 1.5rem;
        margin-bottom: 2rem;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 2rem;
    }
    
    .system-info {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1.5rem;
    }
    
    .info-item {
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    
    .info-item i {
        font-size: 1.5rem;
        color: #667eea;
    }
    
    .info-item div {
        display: flex;
        flex-direction: column;
    }
    
    .info-item small {
        font-size: 0.75rem;
        color: #a0aec0;
    }
    
    .info-item strong {
        font-size: 0.9rem;
        color: #e2e8f0;
    }
    
    .quick-stats {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    
    .quick-stats .stat-item {
        display: flex;
        align-items: center;
        gap: 1rem;
        font-size: 0.9rem;
    }
    
    .stat-icon.online {
        width: 10px;
        height: 10px;
        background: #48bb78;
        border-radius: 50%;
        animation: pulse 2s infinite;
    }
    
    @keyframes pulse {
        0% { opacity: 1; }
        50% { opacity: 0.5; }
        100% { opacity: 1; }
    }
    
    .footer-bottom {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-top: 1.5rem;
        border-top: 1px solid #4a5568;
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .copyright p {
        margin: 0.25rem 0;
        font-size: 0.85rem;
        color: #a0aec0;
    }
    
    .copyright i {
        margin-right: 0.5rem;
    }
    
    .last-update {
        font-size: 0.8rem !important;
        color: #718096 !important;
    }
    
    .footer-actions {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    
    .btn-footer-action {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background: rgba(255,255,255,0.1);
        color: white;
        border: 1px solid rgba(255,255,255,0.2);
        padding: 0.5rem 1rem;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.85rem;
        transition: all 0.3s;
    }
    
    .btn-footer-action:hover {
        background: rgba(255,255,255,0.2);
        transform: translateY(-2px);
    }
    
    .btn-support {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border: none;
    }
    
    /* Support Chat Styles */
    .support-chat {
        position: fixed;
        bottom: 80px;
        right: 20px;
        width: 350px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        display: none;
        flex-direction: column;
        z-index: 1000;
        overflow: hidden;
    }
    
    .support-chat.open {
        display: flex;
    }
    
    .chat-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 1rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .chat-header h5 {
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 1rem;
    }
    
    .chat-close {
        background: none;
        border: none;
        color: white;
        cursor: pointer;
        font-size: 1.2rem;
    }
    
    .chat-messages {
        flex: 1;
        max-height: 300px;
        overflow-y: auto;
        padding: 1rem;
        background: #f8f9fa;
    }
    
    .message {
        margin-bottom: 1rem;
        display: flex;
    }
    
    .user-message {
        justify-content: flex-end;
    }
    
    .support-message {
        justify-content: flex-start;
    }
    
    .message-content {
        max-width: 70%;
        padding: 0.75rem;
        border-radius: 12px;
        position: relative;
    }
    
    .user-message .message-content {
        background: #667eea;
        color: white;
        border-bottom-right-radius: 4px;
    }
    
    .support-message .message-content {
        background: white;
        border: 1px solid #e2e8f0;
        border-bottom-left-radius: 4px;
    }
    
    .message-content p {
        margin: 0 0 0.25rem 0;
        font-size: 0.9rem;
    }
    
    .message-content small {
        font-size: 0.7rem;
        opacity: 0.7;
    }
    
    .chat-input {
        display: flex;
        padding: 1rem;
        border-top: 1px solid #e2e8f0;
    }
    
    .chat-input input {
        flex: 1;
        padding: 0.75rem;
        border: 1px solid #e2e8f0;
        border-radius: 6px 0 0 6px;
        font-size: 0.9rem;
    }
    
    .chat-input button {
        background: #667eea;
        color: white;
        border: none;
        padding: 0 1.5rem;
        border-radius: 0 6px 6px 0;
        cursor: pointer;
    }
    
    .chat-toggle-btn {
        position: fixed;
        bottom: 20px;
        right: 20px;
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        z-index: 999;
        transition: transform 0.3s;
    }
    
    .chat-toggle-btn:hover {
        transform: scale(1.1);
    }
    
    /* System Info Modal */
    .system-details {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    
    .detail-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem;
        background: #f8f9fa;
        border-radius: 6px;
    }
    
    .detail-row span {
        color: #4a5568;
        font-weight: 500;
    }
    
    .detail-row strong {
        color: #2d3748;
        font-family: monospace;
    }
    
    /* Responsive Design */
    @media (max-width: 992px) {
        .footer-middle {
            grid-template-columns: 1fr;
        }
    }
    
    @media (max-width: 768px) {
        .footer-content {
            padding: 1.5rem;
        }
        
        .footer-bottom {
            flex-direction: column;
            text-align: center;
        }
        
        .footer-actions {
            justify-content: center;
        }
        
        .support-chat {
            width: calc(100% - 40px);
            right: 20px;
            left: 20px;
        }
    }
    
    @media (max-width: 576px) {
        .footer-grid {
            grid-template-columns: 1fr;
        }
        
        .system-info {
            grid-template-columns: 1fr;
        }
        
        .footer-actions {
            flex-direction: column;
            width: 100%;
        }
        
        .btn-footer-action {
            width: 100%;
            justify-content: center;
        }
    }
</style>