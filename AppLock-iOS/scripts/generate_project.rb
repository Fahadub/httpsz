#!/usr/bin/env ruby
# Regenerates AppLock.xcodeproj from the folders in this directory.
# Usage: gem install xcodeproj && ruby scripts/generate_project.rb

require 'xcodeproj'
require 'fileutils'

ROOT = File.expand_path('..', __dir__)
PROJECT_PATH = File.join(ROOT, 'AppLock.xcodeproj')
DEPLOYMENT_TARGET = '17.0'

FileUtils.rm_rf(PROJECT_PATH)
project = Xcodeproj::Project.new(PROJECT_PATH)
project.root_object.attributes['LastUpgradeCheck'] = '1600'
project.root_object.attributes['BuildIndependentTargetsInParallel'] = '1'
project.root_object.development_region = 'en'
project.root_object.known_regions = %w[en ar Base]

project.build_configurations.each do |config|
  config.build_settings.merge!(
    'BASE_BUNDLE_ID' => 'com.example.applock',
    'DEVELOPMENT_TEAM' => '',
    'IPHONEOS_DEPLOYMENT_TARGET' => DEPLOYMENT_TARGET,
    'SWIFT_VERSION' => '5.0',
    'MARKETING_VERSION' => '1.0',
    'CURRENT_PROJECT_VERSION' => '1',
    'CLANG_ENABLE_MODULES' => 'YES',
    'ENABLE_USER_SCRIPT_SANDBOXING' => 'YES',
    'LOCALIZATION_PREFERS_STRING_CATALOGS' => 'NO',
    'SWIFT_EMIT_LOC_STRINGS' => 'YES'
  )
end

# Adds every file under `dir` to `group`, mirroring the folder structure.
# Swift files go to each target in `targets`; .strings and .xcassets become resources.
def add_folder(project, group, dir, targets)
  localized = Hash.new { |h, k| h[k] = [] }

  Dir.children(dir).sort.each do |name|
    next if name.start_with?('.')
    path = File.join(dir, name)

    if name.end_with?('.lproj')
      Dir.children(path).sort.each { |file| localized[file] << name }
    elsif name.end_with?('.xcassets')
      ref = group.new_file(name)
      targets.each { |t| t.resources_build_phase.add_file_reference(ref, true) }
    elsif File.directory?(path)
      add_folder(project, group.new_group(name, name), path, targets)
    else
      ref = group.new_file(name)
      if name.end_with?('.swift')
        targets.each { |t| t.source_build_phase.add_file_reference(ref, true) }
      end
    end
  end

  localized.each do |file, lprojs|
    variant = group.new_variant_group(file)
    lprojs.each do |lproj|
      ref = variant.new_file(File.join(lproj, file))
      ref.name = File.basename(lproj, '.lproj')
    end
    targets.each { |t| t.resources_build_phase.add_file_reference(variant, true) }
  end
end

def configure(target, settings)
  target.build_configurations.each do |config|
    config.build_settings.merge!(
      'CODE_SIGN_STYLE' => 'Automatic',
      'GENERATE_INFOPLIST_FILE' => 'NO',
      'TARGETED_DEVICE_FAMILY' => '1,2',
      'SUPPORTS_MACCATALYST' => 'NO',
      'SUPPORTS_MAC_DESIGNED_FOR_IPHONE_IPAD' => 'NO',
      'SUPPORTS_XR_DESIGNED_FOR_IPHONE_IPAD' => 'NO',
      'IPHONEOS_DEPLOYMENT_TARGET' => DEPLOYMENT_TARGET,
      'SWIFT_VERSION' => '5.0'
    )
    config.build_settings.merge!(settings)
  end
end

app = project.new_target(:application, 'AppLock', :ios, DEPLOYMENT_TARGET, nil, :swift)
configure(app, {
  'PRODUCT_NAME' => '$(TARGET_NAME)',
  'PRODUCT_BUNDLE_IDENTIFIER' => '$(BASE_BUNDLE_ID)',
  'INFOPLIST_FILE' => 'AppLock/Info.plist',
  'CODE_SIGN_ENTITLEMENTS' => 'AppLock/AppLock.entitlements',
  'ASSETCATALOG_COMPILER_APPICON_NAME' => 'AppIcon',
  'ASSETCATALOG_COMPILER_GLOBAL_ACCENT_COLOR_NAME' => 'AccentColor',
  'LD_RUNPATH_SEARCH_PATHS' => ['$(inherited)', '@executable_path/Frameworks'],
  'ENABLE_PREVIEWS' => 'YES'
})

extensions = {
  'ShieldConfiguration' => 'ShieldConfiguration',
  'ShieldAction' => 'ShieldAction',
  'ActivityMonitor' => 'ActivityMonitor'
}.map do |name, folder|
  ext = project.new_target(:app_extension, name, :ios, DEPLOYMENT_TARGET, nil, :swift)
  configure(ext, {
    'PRODUCT_NAME' => '$(TARGET_NAME)',
    'PRODUCT_BUNDLE_IDENTIFIER' => "$(BASE_BUNDLE_ID).#{name}",
    'INFOPLIST_FILE' => "#{folder}/Info.plist",
    'CODE_SIGN_ENTITLEMENTS' => "#{folder}/#{name}.entitlements",
    'SKIP_INSTALL' => 'YES',
    'APPLICATION_EXTENSION_API_ONLY' => 'YES',
    'LD_RUNPATH_SEARCH_PATHS' => ['$(inherited)', '@executable_path/Frameworks', '@executable_path/../../Frameworks']
  })
  [ext, folder]
end

# Sources
add_folder(project, project.main_group.new_group('Shared', 'Shared'), File.join(ROOT, 'Shared'),
           [app] + extensions.map(&:first))
add_folder(project, project.main_group.new_group('AppLock', 'AppLock'), File.join(ROOT, 'AppLock'), [app])
extensions.each do |ext, folder|
  add_folder(project, project.main_group.new_group(folder, folder), File.join(ROOT, folder), [ext])
end

# Embed the extensions in the app
embed = app.new_copy_files_build_phase('Embed Foundation Extensions')
embed.symbol_dst_subfolder_spec = :plug_ins
extensions.each do |ext, _|
  app.add_dependency(ext)
  build_file = embed.add_file_reference(ext.product_reference, true)
  build_file.settings = { 'ATTRIBUTES' => ['RemoveHeadersOnCopy'] }
end

# Remove the default Foundation.framework references; system frameworks are auto-linked.
project.targets.each do |target|
  target.frameworks_build_phase.files.dup.each do |file|
    file.remove_from_project
  end
end
frameworks = project.main_group.children.find { |child| child.display_name == 'Frameworks' }
if frameworks
  frameworks.recursive_children.select { |c| c.is_a?(Xcodeproj::Project::Object::PBXFileReference) }.each(&:remove_from_project)
  frameworks.children.dup.each(&:remove_from_project)
  frameworks.remove_from_project
end

project.sort(groups_position: :above)
project.save

scheme = Xcodeproj::XCScheme.new
scheme.configure_with_targets(app, nil, launch_target: true)
scheme.save_as(PROJECT_PATH, 'AppLock', true)

puts "Generated #{PROJECT_PATH}"
