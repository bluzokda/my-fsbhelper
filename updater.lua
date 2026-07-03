
local encoding = require 'encoding'
encoding.default = 'CP1251'

local M = {}


local DL_STATUS
local fsbhelper, state
local saveConfig




local updater = {
    json_url = 'http://wh33027.web2.maze-tech.ru/api.php?action=update_check',
    temp_json = getWorkingDirectory() .. '\\FSBHelper\\~update_temp.json'
}

local pending_update_url = nil
local scriptupd_registered = false

local function trim(s)
    if type(s) ~= 'string' then return '' end
    return (s:gsub('^%s+', ''):gsub('%s+$', ''))
end

local function normalizeChangelog(value)
    local items = {}

    if type(value) == 'table' then
        for _, item in ipairs(value) do
            local text = trim(tostring(item or ''))
            if text ~= '' then table.insert(items, text) end
        end
    elseif type(value) == 'string' then
        value = value:gsub('\r\n', '\n'):gsub('\r', '\n')
        for line in value:gmatch('[^\n]+') do
            line = trim(line):gsub('^[%-*]%s*', '')
            if line ~= '' then table.insert(items, line) end
        end
    end

    return items
end

local function applyApiNews(data)
    if not state then return {} end

    local news_text = data.news or data.changelog or ''
    local items = normalizeChangelog(news_text)
    if #items == 0 then return {} end

    local version = tostring(data.last or data.version or 'latest')
    local uploaded_at = tonumber(data.uploaded_at or 0) or 0
    local date = os.date('%d.%m.%Y', uploaded_at > 0 and uploaded_at or os.time())

    state.api_news_entries = {{
        id = 'api_' .. version:gsub('[^%w_%-%.]', '_'),
        ver = 'v' .. version,
        date = date,
        title = 'Íîâîñòè ñ ñàéòà',
        short_desc = items[1],
        extra_h = 12,
        hc = {0.35, 0.65, 1.0, 1.0},
        bg = {0.08, 0.12, 0.20, 0.65},
        items = items
    }}

    return items
end
local function parseVersionParts(version)
    local parts = {}
    version = tostring(version or '')
    for part in version:gmatch('%d+') do
        table.insert(parts, tonumber(part) or 0)
    end
    return parts
end

local function isRemoteVersionNewer(remote, current)
    remote = tostring(remote or '')
    current = tostring(current or '')
    if remote == '' or remote == '0.0.0' or remote == current then return false end

    local r = parseVersionParts(remote)
    local c = parseVersionParts(current)
    if #r == 0 then return false end

    local max_len = math.max(#r, #c)
    for i = 1, max_len do
        local rv = r[i] or 0
        local cv = c[i] or 0
        if rv > cv then return true end
        if rv < cv then return false end
    end

    return false
end
local function registerUpdateCommands()
    if scriptupd_registered then return end
    scriptupd_registered = true

    sampRegisterChatCommand('scriptupd', function()
        if pending_update_url then
            updater:download(pending_update_url)
        elseif isSampAvailable() then
            sampAddChatMessage('{FF6600}[FSBHELPER]{FFFFFF} Îáíîâëåíèå ñêðèïòà íå íàéäåíî.', -1)
        end
    end)
end

function updater:check(show_chat)
    registerUpdateCommands()
    if doesFileExist(self.temp_json) then os.remove(self.temp_json) end

    local temp_dir = self.temp_json:match('^(.+)\\[^\\]+$')
    if temp_dir and not doesDirectoryExist(temp_dir) then
        createDirectory(temp_dir)
    end

    local handled = false

    lua_thread.create(function()
        wait(12000)
        if not handled and show_chat and isSampAvailable() then
            sampAddChatMessage('{FF6600}[FSBHELPER]{FFFFFF} Ñåðâåð îáíîâëåíèé íå îòâåòèë çà 12 ñåêóíä.', -1)
        end
    end)

    downloadUrlToFile(self.json_url, self.temp_json, function(id, status)
        if status == DL_STATUS.STATUSEX_ENDDOWNLOAD then
            handled = true

            local file = io.open(self.temp_json, 'rb')
            if not file then
                print('[FSBHELPER] Íå óäàëîñü ïðî÷èòàòü ôàéë îòâåòà îáíîâëåíèé')
                if show_chat and isSampAvailable() then
                    sampAddChatMessage('{FF3333}[FSBHELPER]{FFFFFF} Íå óäàëîñü ïðî÷èòàòü îòâåò ñåðâåðà îáíîâëåíèé.', -1)
                end
                return
            end

            local content = file:read('*all')
            file:close()
            os.remove(self.temp_json)

            if content:sub(1, 3) == '\xEF\xBB\xBF' then
                content = content:sub(4)
            end

            local decode_ok, decoded = pcall(encoding.UTF8.decode, encoding.UTF8, content)
            if decode_ok and decoded then
                content = decoded
            end

            local success, data = pcall(decodeJson, content)
            if success and data and data.last and data.url then
                local changelog_items = applyApiNews(data)
                if isRemoteVersionNewer(data.last, thisScript().version) then
                    state.update_available.active = true
                    state.update_available.current_ver = thisScript().version
                    state.update_available.new_ver = data.last
                    state.update_available.url = data.url
                    state.update_available.download_status = ''
                    state.update_available.changelog = changelog_items
                    pending_update_url = data.url

                    if show_chat and isSampAvailable() then
                        sampAddChatMessage(string.format('{66FFCC}[FSBHELPER]{FFFFFF} Äîñòóïíî îáíîâëåíèå: %s -> %s. Íàïèøè /scriptupd äëÿ óñòàíîâêè.', tostring(thisScript().version), tostring(data.last)), -1)
                    end

                else
                    state.update_available.active = false
                    state.update_available.download_status = ''
                    pending_update_url = nil
                    print('[FSBHELPER] Âåðñèÿ àêòóàëüíà: ' .. thisScript().version)
                    if show_chat and isSampAvailable() then
                        sampAddChatMessage('{66FFCC}[FSBHELPER]{FFFFFF} Îáíîâëåíèé íåò. Òåêóùàÿ âåðñèÿ: ' .. tostring(thisScript().version), -1)
                    end
                end
            else
                print('[FSBHELPER] Îøèáêà ïàðñèíãà JSON îáíîâëåíèÿ')
                if show_chat and isSampAvailable() then
                    sampAddChatMessage('{FF3333}[FSBHELPER]{FFFFFF} Îøèáêà îòâåòà ñåðâåðà îáíîâëåíèé.', -1)
                end
            end
        elseif status == DL_STATUS.STATUSEX_HTTPERROR then
            handled = true
            print('[FSBHELPER] Îøèáêà ñåòè ïðè ïðîâåðêå îáíîâëåíèÿ')
            if show_chat and isSampAvailable() then
                sampAddChatMessage('{FF3333}[FSBHELPER]{FFFFFF} Îøèáêà ñåòè ïðè ïðîâåðêå îáíîâëåíèÿ.', -1)
            end
        end
    end)
end

function updater:download(url)
    local temp_path = thisScript().path .. ".tmp"
    
    state.update_available.download_status = 'downloading'
    
    downloadUrlToFile(url, temp_path, function(id, status)
        if status == DL_STATUS.STATUSEX_ENDDOWNLOAD then
            local file = io.open(temp_path, 'rb')
            if not file then 
                state.update_available.download_status = 'error'
                return 
            end
            local content = file:read('*all')
            file:close()

            if content:sub(1, 3) == '\xEF\xBB\xBF' then
                content = content:sub(4)
                local decode_ok, decoded = pcall(encoding.UTF8.decode, encoding.UTF8, content)
                if decode_ok and decoded then
                    content = decoded
                end
            end

            local expected_version = state.update_available.new_ver or ''
            if expected_version ~= '' and not content:find(expected_version, 1, true) then
                pcall(os.remove, temp_path)
                state.update_available.download_status = 'error'
                print('[FSBHELPER] Ñêà÷àííûé ôàéë íå ñîäåðæèò îæèäàåìóþ âåðñèþ ' .. expected_version)
                return
            end

            local out_file = io.open(temp_path, 'wb')
            if out_file then
                out_file:write(content)
                out_file:close()

                os.remove(thisScript().path)
                if os.rename(temp_path, thisScript().path) then
                    state.update_available.download_status = 'done'
                    fsbhelper.config.lastChangelogVersion = "0"
                    saveConfig()

                    if isSampAvailable() then
                        sampAddChatMessage('{66FFCC}[FSBHELPER]{FFFFFF} Îáíîâëåíèå óñòàíîâëåíî.', -1)
                        sampAddChatMessage('{FFCC66}[FSBHELPER]{FFFFFF} Åñëè íóæíî óäàëèòü ñòàðûå lib: ïîëíîñòüþ çàêðîé èãðó.', -1)
                        sampAddChatMessage('{FFCC66}[FSBHELPER]{FFFFFF} Çàòåì óäàëè ïàïêó: moonloader\\lib\\fsbhelper', -1)
                    end
                    
                    thisScript():reload()
                else
                    state.update_available.download_status = 'error'
                end
            else
                state.update_available.download_status = 'error'
            end
        elseif status == DL_STATUS.STATUSEX_HTTPERROR then
            state.update_available.download_status = 'error'
        end
    end)
end




local function downloadResource(url, path, resource_type, callback)
    local dir = path:match('(.+)\\[^\\]+$')
    if dir and not doesDirectoryExist(dir) then
        createDirectory(dir)
    end

    if resource_type == "text" then
        if fsbhelper.config.autoUpdateTexts then
            print('[FSBHELPER] Îáíîâëÿþ òåêñò: ' .. path:match('([^\\]+)$'))
            downloadUrlToFile(url, path, function(id, status)
                if status == DL_STATUS.STATUSEX_ENDDOWNLOAD then
                    if callback then callback(true) end
                end
            end)
            return true
        else
            if not doesFileExist(path) then
                print('[FSBHELPER] Ñêà÷èâàþ òåêñò: ' .. path:match('([^\\]+)$'))
                downloadUrlToFile(url, path, function(id, status)
                    if status == DL_STATUS.STATUSEX_ENDDOWNLOAD then
                        if callback then callback(true) end
                    end
                end)
                return true
            else
                print('[FSBHELPER] Ïðîïóñêàþ: ' .. path:match('([^\\]+)$'))
                if callback then callback(false) end
                return false
            end
        end
    end

    if not doesFileExist(path) then
        print('[FSBHELPER] Ñêà÷èâàþ: ' .. path:match('([^\\]+)$'))
        downloadUrlToFile(url, path, function(id, status)
            if status == DL_STATUS.STATUSEX_ENDDOWNLOAD then
                if callback then callback(true) end
            end
        end)
        return true
    end
    if callback then callback(false) end
    return false
end




local function checkAndDownloadResources()
    local base_url = "http://wh33027.web2.maze-tech.ru/api.php?action=dl&path=resource/"
    local base_path = getWorkingDirectory() .. "\\FSBHelper\\"

    local downloaded_count = 0
    local processed_count = 0
    local images_downloaded = false

    local resources = {
        {url = base_url .. "texts/hierarchy.txt", path = base_path .. "texts\\hierarchy.txt", type = "text"},
        {url = base_url .. "texts/labor_code.txt", path = base_path .. "texts\\labor_code.txt", type = "text"},
        {url = base_url .. "texts/legal_constitution.txt", path = base_path .. "texts\\legal_constitution.txt", type = "text"},
        {url = base_url .. "texts/legal_federal.txt", path = base_path .. "texts\\legal_federal.txt", type = "text"},
        {url = base_url .. "texts/legal_fsb_law.txt", path = base_path .. "texts\\legal_fsb_law.txt", type = "text"},
        {url = base_url .. "texts/legal_koap.txt", path = base_path .. "texts\\legal_koap.txt", type = "text"},
        {url = base_url .. "texts/legal_police_law.txt", path = base_path .. "texts\\legal_police_law.txt", type = "text"},
        {url = base_url .. "texts/legal_uk.txt", path = base_path .. "texts\\legal_uk.txt", type = "text"},
        {url = base_url .. "texts/mvd_drill.txt", path = base_path .. "texts\\mvd_drill.txt", type = "text"},
        {url = base_url .. "texts/mvd_handbook.txt", path = base_path .. "texts\\mvd_handbook.txt", type = "text"},
        {url = base_url .. "texts/mvd_statute.txt", path = base_path .. "texts\\mvd_statute.txt", type = "text"},
        {url = base_url .. "texts/police_main.txt", path = base_path .. "texts\\police_main.txt", type = "text"},
        {url = base_url .. "texts/police_mask.txt", path = base_path .. "texts\\police_mask.txt", type = "text"},
        {url = base_url .. "texts/police_radar.txt", path = base_path .. "texts\\police_radar.txt", type = "text"},
        {url = base_url .. "texts/police_tint.txt", path = base_path .. "texts\\police_tint.txt", type = "text"},
        {url = base_url .. "texts/su_menu_articles.txt", path = base_path .. "texts\\su_menu_articles.txt", type = "text"},
        {url = base_url .. "texts/fine_menu_koap.txt", path = base_path .. "texts\\fine_menu_koap.txt", type = "text"},
        {url = base_url .. "texts/territory_army.txt", path = base_path .. "texts\\territory_army.txt", type = "text"},
        {url = base_url .. "texts/territory_army_supplement.txt", path = base_path .. "texts\\territory_army_supplement.txt", type = "text"},
        {url = base_url .. "texts/territory_fsb.txt", path = base_path .. "texts\\territory_fsb.txt", type = "text"},
        {url = base_url .. "texts/territory_fsin.txt", path = base_path .. "texts\\territory_fsin.txt", type = "text"},
        {url = base_url .. "texts/territory_fsin_supplement.txt", path = base_path .. "texts\\territory_fsin_supplement.txt", type = "text"},
        {url = base_url .. "texts/territory_government.txt", path = base_path .. "texts\\territory_government.txt", type = "text"},
        {url = base_url .. "texts/territory_hospital.txt", path = base_path .. "texts\\territory_hospital.txt", type = "text"},
        {url = base_url .. "texts/territory_main.txt", path = base_path .. "texts\\territory_main.txt", type = "text"},
        {url = base_url .. "texts/territory_mchs.txt", path = base_path .. "texts\\territory_mchs.txt", type = "text"},
        {url = base_url .. "texts/territory_mvd.txt", path = base_path .. "texts\\territory_mvd.txt", type = "text"},
        {url = base_url .. "texts/territory_smi.txt", path = base_path .. "texts\\territory_smi.txt", type = "text"},
        {url = base_url .. "texts/upk.txt", path = base_path .. "texts\\upk.txt", type = "text"},

        {url = base_url .. "images/radar_map.png", path = base_path .. "images\\radar_map.png", type = "image"},
        {url = base_url .. "images/ter_1.jpg", path = base_path .. "images\\ter_1.jpg", type = "image"},
        {url = base_url .. "images/ter_2.jpg", path = base_path .. "images\\ter_2.jpg", type = "image"},
        {url = base_url .. "images/ter_3.jpg", path = base_path .. "images\\ter_3.jpg", type = "image"},
        {url = base_url .. "images/ter_4.jpg", path = base_path .. "images\\ter_4.jpg", type = "image"},
        {url = base_url .. "images/ter_5.jpg", path = base_path .. "images\\ter_5.jpg", type = "image"},
        {url = base_url .. "images/ter_6.jpg", path = base_path .. "images\\ter_6.jpg", type = "image"},
        {url = base_url .. "images/ter_7.jpg", path = base_path .. "images\\ter_7.jpg", type = "image"},
        {url = base_url .. "images/ter_8.jpg", path = base_path .. "images\\ter_8.jpg", type = "image"},
        {url = base_url .. "images/ter_9.jpg", path = base_path .. "images\\ter_9.jpg", type = "image"},
        {url = base_url .. "images/ter_10.jpg", path = base_path .. "images\\ter_10.jpg", type = "image"},
        {url = base_url .. "images/ter_11.jpg", path = base_path .. "images\\ter_11.jpg", type = "image"},
        {url = base_url .. "images/ter_12.jpg", path = base_path .. "images\\ter_12.jpg", type = "image"},
        {url = base_url .. "images/ter_13.jpg", path = base_path .. "images\\ter_13.jpg", type = "image"},
        {url = base_url .. "images/ter_14.jpg", path = base_path .. "images\\ter_14.jpg", type = "image"},
        {url = base_url .. "images/ter_15.jpg", path = base_path .. "images\\ter_15.jpg", type = "image"},
        {url = base_url .. "images/ter_16.jpg", path = base_path .. "images\\ter_16.jpg", type = "image"},
        {url = base_url .. "images/ter_17.jpg", path = base_path .. "images\\ter_17.jpg", type = "image"},
        {url = base_url .. "images/ter_18.jpg", path = base_path .. "images\\ter_18.jpg", type = "image"},
        {url = base_url .. "images/ter_19.jpg", path = base_path .. "images\\ter_19.jpg", type = "image"},
        {url = base_url .. "images/ter_20.jpg", path = base_path .. "images\\ter_20.jpg", type = "image"},

        {url = base_url .. "fonts/EagleSans-Regular.ttf", path = base_path .. "fonts\\EagleSans-Regular.ttf", type = "font"},
    }

    local total = #resources
    state.loading.res_total = total
    state.loading.res_done = 0

    for i, res in ipairs(resources) do
        downloadResource(res.url, res.path, res.type, function(was_downloaded)
            processed_count = processed_count + 1
            state.loading.res_done = processed_count
            if was_downloaded then
                downloaded_count = downloaded_count + 1
                if res.type == "image" then
                    images_downloaded = true
                end
            end

            state.loading.detail = string.format('Ðåñóðñû: %d/%d', processed_count, total)
            state.loading.progress = 0.80 + (processed_count / total) * 0.20

            if processed_count == total then
                if downloaded_count > 0 then
                    print('[FSBHELPER] Çàãðóæåíî íîâûõ ðåñóðñîâ: ' .. downloaded_count)
                    if images_downloaded and fsbhelper.config.showImages then
                        state.textures.lazy_loaded = false
                    end
                end
                state.loading.phase = 'done'
                state.loading.progress = 1.0
                state.loading.detail = ''
            end
        end)

        wait(100)
    end
end




function M.init(deps)
   DL_STATUS  = deps.DL_STATUS
   fsbhelper      = deps.fsbhelper
   state      = deps.state
   saveConfig = deps.saveConfig
end

M.updater                    = updater
M.downloadResource           = downloadResource
M.checkAndDownloadResources  = checkAndDownloadResources

function M.trigger_update()
   if pending_update_url then
      updater:download(pending_update_url)
   end
end

return M
