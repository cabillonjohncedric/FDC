<?php
session_name('patient_session');
session_start();
include_once "../Config/conn.config.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

$user_id = $_SESSION['user_id'];

//Retreives Appointment History
try {
    $history = $conn->prepare("SELECT aps.*, a.total_expense, af.file_path FROM appointments AS a 
                               LEFT JOIN appointment_schedule AS aps ON a.appointment_id = aps.appointment_id
                               LEFT JOIN appointment_files AS af ON a.appointment_id = af.appointment_id
                               WHERE aps.user_id = ? ORDER BY aps.appointment_date DESC, aps.appointment_time_start DESC");
    $history->execute([$user_id]);
    $historyRecords = $history->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}


//Retreives Patient Records
try {
    $precords = $conn->prepare("SELECT * FROM patient_records WHERE user_id = ? ");
    $precords->execute([$user_id]);
    $patientRecords = $precords->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}

//Retreives user info
try {
    $user = $conn->prepare("SELECT up.*, uc.profile_picture FROM user_patient up 
                               LEFT JOIN user_credentials uc ON up.user_id = uc.user_id 
                               WHERE up.user_id = ?");
    $user->execute([$user_id]);
    $userProfile = $user->fetch(PDO::FETCH_ASSOC);

    $profile_picture = !empty($userProfile['profile_picture'])
        ? "../uploads/" . htmlspecialchars($userProfile['profile_picture'])
        : "../uploads/user.png";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>


<!DOCTYPE html>
<html lang="en">

<?php include_once "../Includes/Head.php"; ?>

<body>

    <?php include_once "../Includes/Header.php"; ?>

    <?php include_once "../Includes/Sidebar.php"; ?>


    <main id="main" class="main">
        <div class="chatbox-container">
            <!-- Sidebar -->
            <div class="chatbox-sidebar">
                <div class="chatbox-sidebar-header">
                    <h2 class="chatbox-title">Recent Conversations</h2>
                    <p class="chatbox-subtitle">Active patient communications</p>
                    <div class="chatbox-stats">
                        <div class="chatbox-stat">
                            <div class="chatbox-dot online"></div>
                            <span id="online-count">0 Online</span>
                        </div>
                        <div class="chatbox-stat">
                            <div class="chatbox-dot offline"></div>
                            <span id="offline-count">0 Offline</span>
                        </div>
                    </div>
                </div>

                <div class="chatbox-search">
                    <input type="text" class="chatbox-search-input" placeholder="Search conversations...">
                </div>

                <div class="chatbox-contacts" id="chatbox-contacts"></div>
            </div>

            <!-- Chat Area -->
            <div class="chatbox-main">
                <div class="chatbox-header">
                    <button class="chatbox-back-btn" id="chatbox-back-btn" style="display:none;">
                        <i class="bi bi-arrow-left"></i>
                    </button>
                    <div class="chatbox-header-info">
                        <div class="chatbox-breadcrumb">
                            <span>Messages</span>
                            <span class="chatbox-separator">›</span>
                            <span id="active-contact-name">Select a contact</span>
                        </div>
                        <div class="chatbox-user" id="chatbox-user-info">
                            <!-- Filled dynamically -->
                        </div>
                    </div>
                </div>

                <div class="chatbox-messages" id="chatbox-messages"></div>

                <div class="chatbox-input-area">
                    <textarea class="chatbox-input" placeholder="Type your message..."></textarea>
                    <button class="chatbox-send mt-2">
                        <i class="fi fi-tr-paper-plane-top" style="margin-left: -2px;"></i>
                    </button>
                </div>
            </div>
        </div>
    </main>

    <?php include_once "../Includes/Footer.php"; ?>

    <!-- ====== CHAT SCRIPTS ====== -->
    <script>
        let activeContactIndex = 0;
        let contacts = [];
        let lastMessageId = {};
        const patient_id = <?= json_encode($user_id); ?>;

        async function fetchContacts() {
            try {
                const res = await fetch('../Auth/User/Fetch_Contact.php?patient_id=' + patient_id);
                contacts = await res.json();
                contacts.sort((a, b) => new Date(b.time || 0) - new Date(a.time || 0));
                renderContacts(contacts);
            } catch (err) {
                console.error('Error fetching contacts:', err);
            }
        }

        function renderContacts(data) {
            const list = document.getElementById('chatbox-contacts');
            const onlineCount = document.getElementById('online-count');
            const offlineCount = document.getElementById('offline-count');
            list.innerHTML = '';
            let online = 0,
                offline = 0;

            if (!data.length) {
                list.innerHTML = `<div class="text-center p-3 text-muted">No doctors available</div>`;
                document.getElementById('chatbox-messages').innerHTML = '';
                onlineCount.textContent = '0 Online';
                offlineCount.textContent = '0 Offline';
                return;
            }

            data.forEach((d, i) => {
                if (d.isOnline === 'Online') online++;
                else offline++;

                const avatar = d.profile ?
                    `<img src="../uploads/${d.profile}" style="width:45px;height:45px;border-radius:50%;object-fit:cover;">` :
                    `<div class="chatbox-avatar-text">${d.initials}</div>`;

                const statusHTML = d.isOnline === 'Online' ? `<div class="chatbox-status online"></div>` : '';
                const preview = d.lastMsg ? (d.lastMsgFrom === 'user' ? `You: ${d.lastMsg}` : d.lastMsg) : 'No messages yet';

                const div = document.createElement('div');
                div.classList.add('chatbox-contact');
                if (i === activeContactIndex) div.classList.add('active');

                div.innerHTML = `
                    <div class="chatbox-avatar">${avatar}${statusHTML}</div>
                    <div class="chatbox-contact-info">
                        <div class="chatbox-name">${d.name}</div>
                        <div class="chatbox-role">Clinic Moderator</div>
                        <div class="chatbox-lastmsg">${preview}</div>
                    </div>
                    <div class="chatbox-time">${d.time || ''}</div>
                `;

                div.addEventListener('click', () => {
                    activeContactIndex = i;
                    loadChat(d, true);
                });

                list.appendChild(div);
            });

            onlineCount.textContent = `${online} Online`;
            offlineCount.textContent = `${offline} Offline`;
            loadChat(data[activeContactIndex], true);
        }

        async function loadChat(d, forceReload = false) {
            const allContacts = document.querySelectorAll('.chatbox-contact');
            allContacts.forEach(el => el.classList.remove('active'));
            allContacts[activeContactIndex]?.classList.add('active');

            const msgContainer = document.getElementById('chatbox-messages');
            const chatInfo = document.getElementById('chatbox-user-info');
            document.getElementById('active-contact-name').textContent = d.name;

            const avatarHTML = d.profile ?
                `<img src="../uploads/${d.profile}" style="width:50px;height:50px;border-radius:50%;object-fit:cover;">` :
                `<div class="chatbox-avatar-text">${d.initials}</div>`;

            const statusDot = d.isOnline === 'Online' ?
                `<div class="chatbox-dot online"></div>` :
                `<div class="chatbox-dot offline"></div>`;

            chatInfo.innerHTML = `
                ${avatarHTML}
                <div>
                    <div class="chatbox-user-name">${d.name}</div>
                    <div class="chatbox-user-status">${statusDot} ${d.isOnline} • Clinic Moderator</div>
                </div>
            `;

            msgContainer.innerHTML = `<div class="text-center text-muted p-3">Loading messages...</div>`;

            try {
                const res = await fetch(`../Auth/User/get_messages.php?doctor_id=${d.doc_id}`);
                const messages = await res.json();
                msgContainer.innerHTML = '';

                if (!messages.length) {
                    msgContainer.innerHTML = `<div class="text-center text-muted p-3">No messages yet</div>`;
                } else {
                    messages.forEach(msg => appendMessage(msg, d, msgContainer));
                    lastMessageId[d.doc_id] = Math.max(...messages.map(m => m.id || 0));
                }

                d.messages = messages;
            } catch (err) {
                console.error('Error loading chat:', err);
                msgContainer.innerHTML = `<div class="text-center text-danger p-3">Failed to load messages.</div>`;
            }
        }

        function appendMessage(msg, contact, container) {
            if (msg.id && container.querySelector(`[data-id="${msg.id}"]`)) return;

            const div = document.createElement('div');
            div.classList.add('chatbox-message');
            div.dataset.id = msg.id || '';
            if (msg.from === 'user') div.classList.add('sent');

            div.innerHTML = msg.from === 'personnel' ?
                `<div class="chatbox-msg-avatar">
                    ${contact.profile ? `<img src="../uploads/${contact.profile}" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">` : contact.initials}
                </div>
                <div class="chatbox-msg-content">
                    <div class="chatbox-msg-text">${msg.text}</div>
                    <div class="chatbox-msg-time">${msg.time}</div>
                </div>` :
                `<div class="chatbox-msg-content">
                    <div class="chatbox-msg-text">${msg.text}</div>
                    <div class="chatbox-msg-time">${msg.time}</div>
                </div>
                <div class="chatbox-msg-avatar">You</div>`;

            container.appendChild(div);
            container.scrollTop = container.scrollHeight;

            if (msg.id)
                lastMessageId[contact.doc_id] = Math.max(lastMessageId[contact.doc_id] || 0, msg.id);
        }

        async function fetchNewMessages() {
            if (!contacts.length) return;
            const current = contacts[activeContactIndex];
            const afterId = lastMessageId[current.doc_id] || 0;

            try {
                const res = await fetch(`../Auth/User/get_messages.php?doctor_id=${current.doc_id}&after_id=${afterId}`);
                const messages = await res.json();

                if (!messages.length) return;
                const container = document.getElementById('chatbox-messages');
                messages.forEach(msg => {
                    current.messages.push(msg);
                    appendMessage(msg, current, container);
                });
            } catch (err) {
                console.error('Error fetching new messages:', err);
            }
        }

        document.querySelector('.chatbox-send').addEventListener('click', sendMessage);
        document.querySelector('.chatbox-input').addEventListener('keypress', e => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        async function sendMessage() {
            const textarea = document.querySelector('.chatbox-input');
            const text = textarea.value.trim();
            if (!text || !contacts.length) return;

            const current = contacts[activeContactIndex];
            textarea.value = '';

            try {
                const res = await fetch('../Auth/User/Send_Message.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        doc_id: current.doc_id,
                        text
                    })
                });
                const data = await res.json();

                if (data.status === 'success') {
                    const msgObj = {
                        id: Number(data.id || Date.now()),
                        from: 'user',
                        text,
                        time: new Date().toLocaleTimeString([], {
                            hour: '2-digit',
                            minute: '2-digit'
                        })
                    };
                    current.messages.push(msgObj);
                    appendMessage(msgObj, current, document.getElementById('chatbox-messages'));
                }
            } catch (err) {
                console.error('Send message error:', err);
            }
        }

        fetchContacts();
        setInterval(fetchNewMessages, 2000);
    </script>

    <!-- ====== RESPONSIVE BEHAVIOR ====== -->
    <script>
        const chatboxSidebar = document.querySelector('.chatbox-sidebar');
        const chatboxMain = document.querySelector('.chatbox-main');
        const backBtn = document.getElementById('chatbox-back-btn');

        function isMobileView() {
            return window.innerWidth <= 480;
        }

        document.addEventListener('click', (e) => {
            if (e.target.closest('.chatbox-contact') && isMobileView()) {
                chatboxSidebar.style.display = 'none';
                chatboxMain.style.display = 'flex';
                backBtn.style.display = 'inline-block';
            }
        });

        backBtn.addEventListener('click', () => {
            if (isMobileView()) {
                chatboxMain.style.display = 'none';
                chatboxSidebar.style.display = 'block';
                backBtn.style.display = 'none';
            }
        });

        window.addEventListener('resize', () => {
            if (!isMobileView()) {
                chatboxSidebar.style.display = 'flex';
                chatboxMain.style.display = 'flex';
                backBtn.style.display = 'none';
            } else {
                chatboxSidebar.style.display = 'block';
                chatboxMain.style.display = 'none';
                backBtn.style.display = 'none';
            }
        });
    </script>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flaticon@8.0.0/css/flaticon.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Vendor JS Files -->
    <script src="../Assets/vendor/apexcharts/apexcharts.min.js"></script>
    <script src="../Assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../Assets/vendor/chart.js/chart.umd.js"></script>
    <script src="../Assets/vendor/echarts/echarts.min.js"></script>
    <script src="../Assets/vendor/quill/quill.js"></script>
    <script src="../Assets/vendor/simple-datatables/simple-datatables.js"></script>
    <script src="../Assets/vendor/tinymce/tinymce.min.js"></script>
    <script src="../Assets/vendor/php-email-form/validate.js"></script>

    <!-- Template Main JS File -->
    <script src="../Assets/js/main.js"></script>
    <!-- SweetAlert2 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <?php include_once "../Includes/SweetAlert.php"; ?>

</body>

</html>