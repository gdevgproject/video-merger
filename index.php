<!DOCTYPE html>
<html lang="vi">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Video & SRT Merger</title>
    <style>
      * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
      }

      body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
          Oxygen, Ubuntu, Cantarell, sans-serif;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        min-height: 100vh;
        padding: 20px;
      }

      .container {
        max-width: 1000px;
        margin: 0 auto;
        background: white;
        border-radius: 20px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        padding: 40px;
      }

      h1 {
        color: #333;
        margin-bottom: 30px;
        text-align: center;
        font-size: 2.5em;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
      }

      .form-group {
        margin-bottom: 25px;
      }

      label {
        display: block;
        margin-bottom: 8px;
        color: #555;
        font-weight: 600;
        font-size: 14px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
      }

      input[type="text"] {
        width: 100%;
        padding: 15px;
        border: 2px solid #e0e0e0;
        border-radius: 10px;
        font-size: 16px;
        transition: all 0.3s;
      }

      input[type="text"]:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
      }

      .btn {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 15px 40px;
        border: none;
        border-radius: 10px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        width: 100%;
        transition: transform 0.2s, box-shadow 0.2s;
        text-transform: uppercase;
        letter-spacing: 1px;
      }

      .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
      }

      .btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
      }

      .progress-section {
        display: none;
        margin-top: 30px;
        padding: 25px;
        background: #f8f9fa;
        border-radius: 15px;
      }

      .progress-section.active {
        display: block;
      }

      .progress-item {
        margin-bottom: 20px;
        padding: 15px;
        background: white;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
      }

      .progress-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
      }

      .progress-title {
        font-weight: 600;
        color: #333;
        font-size: 14px;
      }

      .progress-status {
        font-size: 12px;
        padding: 4px 12px;
        border-radius: 20px;
        font-weight: 600;
      }

      .status-pending {
        background: #fff3cd;
        color: #856404;
      }

      .status-processing {
        background: #cce5ff;
        color: #004085;
        animation: pulse 1.5s infinite;
      }

      .status-complete {
        background: #d4edda;
        color: #155724;
      }

      .status-error {
        background: #f8d7da;
        color: #721c24;
      }

      @keyframes pulse {
        0%,
        100% {
          opacity: 1;
        }
        50% {
          opacity: 0.7;
        }
      }

      .progress-bar-container {
        width: 100%;
        height: 8px;
        background: #e0e0e0;
        border-radius: 10px;
        overflow: hidden;
        margin-bottom: 8px;
      }

      .progress-bar {
        height: 100%;
        background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        width: 0%;
        transition: width 0.3s;
        border-radius: 10px;
      }

      .progress-text {
        font-size: 12px;
        color: #666;
        display: flex;
        justify-content: space-between;
      }

      .file-list {
        margin-top: 15px;
        padding: 15px;
        background: #f8f9fa;
        border-radius: 8px;
        max-height: 200px;
        overflow-y: auto;
      }

      .file-item {
        padding: 8px;
        margin-bottom: 5px;
        background: white;
        border-radius: 5px;
        font-size: 13px;
        display: flex;
        align-items: center;
      }

      .file-item:before {
        content: "📄";
        margin-right: 8px;
      }

      .summary {
        display: none;
        margin-top: 20px;
        padding: 20px;
        background: #d4edda;
        border-radius: 10px;
        border-left: 4px solid #28a745;
      }

      .summary.active {
        display: block;
      }

      .summary h3 {
        color: #155724;
        margin-bottom: 15px;
      }

      .summary-item {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid #c3e6cb;
      }

      .summary-item:last-child {
        border-bottom: none;
      }

      .error-message {
        display: none;
        margin-top: 20px;
        padding: 15px;
        background: #f8d7da;
        border-radius: 10px;
        color: #721c24;
        border-left: 4px solid #dc3545;
      }

      .error-message.active {
        display: block;
      }

      .spinner {
        display: inline-block;
        width: 14px;
        height: 14px;
        border: 2px solid #004085;
        border-top-color: transparent;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
        margin-left: 8px;
      }

      @keyframes spin {
        to {
          transform: rotate(360deg);
        }
      }
    </style>
  </head>
  <body>
    <div class="container">
      <h1>🎬 Video & SRT Merger</h1>

      <form id="mergeForm">
        <div class="form-group">
          <label for="inputPath">📁 Thư mục chứa video & SRT</label>
          <input
            type="text"
            id="inputPath"
            name="inputPath"
            placeholder="C:\Videos\Course"
            required
          />
        </div>

        <div class="form-group">
          <label for="outputPath">💾 Thư mục xuất kết quả</label>
          <input
            type="text"
            id="outputPath"
            name="outputPath"
            placeholder="C:\Videos\Output"
            required
          />
        </div>

        <button type="submit" class="btn" id="submitBtn">Bắt đầu gộp</button>
      </form>

      <div class="progress-section" id="progressSection">
        <div class="progress-item">
          <div class="progress-header">
            <span class="progress-title">🔍 Quét và phân tích files</span>
            <span class="progress-status status-pending" id="scanStatus"
              >Chờ xử lý</span
            >
          </div>
          <div class="progress-bar-container">
            <div class="progress-bar" id="scanProgress"></div>
          </div>
          <div class="progress-text">
            <span id="scanText">Đang chờ...</span>
            <span id="scanTime"></span>
          </div>
          <div class="file-list" id="fileList" style="display: none"></div>
        </div>

        <div class="progress-item">
          <div class="progress-header">
            <span class="progress-title">🎥 Gộp video</span>
            <span class="progress-status status-pending" id="videoStatus"
              >Chờ xử lý</span
            >
          </div>
          <div class="progress-bar-container">
            <div class="progress-bar" id="videoProgress"></div>
          </div>
          <div class="progress-text">
            <span id="videoText">Đang chờ...</span>
            <span id="videoTime"></span>
          </div>
        </div>

        <div class="progress-item">
          <div class="progress-header">
            <span class="progress-title">📝 Gộp phụ đề tiếng Anh</span>
            <span class="progress-status status-pending" id="srtEnStatus"
              >Chờ xử lý</span
            >
          </div>
          <div class="progress-bar-container">
            <div class="progress-bar" id="srtEnProgress"></div>
          </div>
          <div class="progress-text">
            <span id="srtEnText">Đang chờ...</span>
            <span id="srtEnTime"></span>
          </div>
        </div>

        <div class="progress-item">
          <div class="progress-header">
            <span class="progress-title">📝 Gộp phụ đề tiếng Việt</span>
            <span class="progress-status status-pending" id="srtViStatus"
              >Chờ xử lý</span
            >
          </div>
          <div class="progress-bar-container">
            <div class="progress-bar" id="srtViProgress"></div>
          </div>
          <div class="progress-text">
            <span id="srtViText">Đang chờ...</span>
            <span id="srtViTime"></span>
          </div>
        </div>
      </div>

      <div class="summary" id="summary">
        <h3>✅ Hoàn thành!</h3>
        <div class="summary-item">
          <span>Tổng thời gian:</span>
          <strong id="totalTime">0s</strong>
        </div>
        <div class="summary-item">
          <span>Video đã gộp:</span>
          <strong id="videoCount">0 files</strong>
        </div>
        <div class="summary-item">
          <span>Phụ đề EN:</span>
          <strong id="srtEnCount">0 files</strong>
        </div>
        <div class="summary-item">
          <span>Phụ đề VI:</span>
          <strong id="srtViCount">0 files</strong>
        </div>
        <div class="summary-item">
          <span>File output:</span>
          <strong id="outputFile">-</strong>
        </div>
      </div>

      <div class="error-message" id="errorMessage"></div>
    </div>

    <script>
      let startTime;
      let timers = {};

      document
        .getElementById("mergeForm")
        .addEventListener("submit", async (e) => {
          e.preventDefault();

          const inputPath = document.getElementById("inputPath").value.trim();
          const outputPath = document.getElementById("outputPath").value.trim();

          if (!inputPath || !outputPath) {
            alert("Vui lòng nhập đầy đủ thông tin!");
            return;
          }

          startTime = Date.now();
          resetUI();
          document.getElementById("progressSection").classList.add("active");
          document.getElementById("submitBtn").disabled = true;
          document.getElementById("submitBtn").textContent = "Đang xử lý...";

          try {
            await processFiles(inputPath, outputPath);
          } catch (error) {
            showError(error.message);
          } finally {
            document.getElementById("submitBtn").disabled = false;
            document.getElementById("submitBtn").textContent = "Bắt đầu gộp";
          }
        });

      async function processFiles(inputPath, outputPath) {
        // Step 1: Scan files
        await updateStep("scan", "processing", "Đang quét thư mục...");
        const scanResponse = await fetch("process.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ action: "scan", inputPath, outputPath }),
        });

        const scanData = await scanResponse.json();
        if (!scanData.success) throw new Error(scanData.error);

        displayFileList(scanData.files);
        await updateStep(
          "scan",
          "complete",
          `Tìm thấy ${scanData.files.videos.length} video, ${scanData.files.srt_en.length} SRT EN, ${scanData.files.srt_vi.length} SRT VI`,
          100
        );

        // Step 2: Merge videos
        if (scanData.files.videos.length > 0) {
          await updateStep("video", "processing", "Đang gộp video...");
          const videoResponse = await fetch("process.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              action: "merge_video",
              inputPath,
              outputPath,
              videos: scanData.files.videos,
            }),
          });

          const videoData = await videoResponse.json();
          if (!videoData.success) throw new Error(videoData.error);

          await updateStep(
            "video",
            "complete",
            "Video đã được gộp thành công!",
            100
          );
        }

        // Step 3: Merge SRT EN
        if (scanData.files.srt_en.length > 0) {
          await updateStep(
            "srtEn",
            "processing",
            "Đang gộp phụ đề tiếng Anh..."
          );
          const srtEnResponse = await fetch("process.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              action: "merge_srt",
              inputPath,
              outputPath,
              srtFiles: scanData.files.srt_en,
              videos: scanData.files.videos,
              lang: "en",
            }),
          });

          const srtEnData = await srtEnResponse.json();
          if (!srtEnData.success) throw new Error(srtEnData.error);

          await updateStep("srtEn", "complete", "Phụ đề EN đã gộp!", 100);
        }

        // Step 4: Merge SRT VI
        if (scanData.files.srt_vi.length > 0) {
          await updateStep(
            "srtVi",
            "processing",
            "Đang gộp phụ đề tiếng Việt..."
          );
          const srtViResponse = await fetch("process.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              action: "merge_srt",
              inputPath,
              outputPath,
              srtFiles: scanData.files.srt_vi,
              videos: scanData.files.videos,
              lang: "vi",
            }),
          });

          const srtViData = await srtViResponse.json();
          if (!srtViData.success) throw new Error(srtViData.error);

          await updateStep("srtVi", "complete", "Phụ đề VI đã gộp!", 100);
        }

        showSummary(scanData);
      }

      function updateStep(step, status, text, progress = 0) {
        const statusEl = document.getElementById(step + "Status");
        const textEl = document.getElementById(step + "Text");
        const progressEl = document.getElementById(step + "Progress");
        const timeEl = document.getElementById(step + "Time");

        statusEl.className = "progress-status status-" + status;

        if (status === "processing") {
          statusEl.innerHTML = 'Đang xử lý <span class="spinner"></span>';
          timers[step] = setInterval(() => {
            const elapsed = Math.floor((Date.now() - startTime) / 1000);
            timeEl.textContent = formatTime(elapsed);
          }, 100);
        } else if (status === "complete") {
          statusEl.textContent = "Hoàn thành";
          if (timers[step]) clearInterval(timers[step]);
        } else if (status === "error") {
          statusEl.textContent = "Lỗi";
          if (timers[step]) clearInterval(timers[step]);
        }

        textEl.textContent = text;
        progressEl.style.width = progress + "%";

        return new Promise((resolve) => setTimeout(resolve, 100));
      }

      function displayFileList(files) {
        const fileListEl = document.getElementById("fileList");
        fileListEl.style.display = "block";
        fileListEl.innerHTML = "<strong>Files tìm thấy:</strong>";

        files.videos.forEach((file) => {
          const div = document.createElement("div");
          div.className = "file-item";
          div.textContent = file;
          fileListEl.appendChild(div);
        });
      }

      function showSummary(scanData) {
        const totalTime = Math.floor((Date.now() - startTime) / 1000);
        document.getElementById("totalTime").textContent =
          formatTime(totalTime);
        document.getElementById("videoCount").textContent =
          scanData.files.videos.length + " files";
        document.getElementById("srtEnCount").textContent =
          scanData.files.srt_en.length + " files";
        document.getElementById("srtViCount").textContent =
          scanData.files.srt_vi.length + " files";
        document.getElementById("outputFile").textContent = "merged_output.mp4";
        document.getElementById("summary").classList.add("active");
      }

      function showError(message) {
        const errorEl = document.getElementById("errorMessage");
        errorEl.textContent = "❌ Lỗi: " + message;
        errorEl.classList.add("active");
      }

      function formatTime(seconds) {
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = seconds % 60;
        return h > 0 ? `${h}h ${m}m ${s}s` : m > 0 ? `${m}m ${s}s` : `${s}s`;
      }

      function resetUI() {
        document.getElementById("summary").classList.remove("active");
        document.getElementById("errorMessage").classList.remove("active");
        document.getElementById("fileList").innerHTML = "";
        document.getElementById("fileList").style.display = "none";

        ["scan", "video", "srtEn", "srtVi"].forEach((step) => {
          document.getElementById(step + "Status").className =
            "progress-status status-pending";
          document.getElementById(step + "Status").textContent = "Chờ xử lý";
          document.getElementById(step + "Text").textContent = "Đang chờ...";
          document.getElementById(step + "Progress").style.width = "0%";
          document.getElementById(step + "Time").textContent = "";
          if (timers[step]) clearInterval(timers[step]);
        });
      }
    </script>
  </body>
</html>
