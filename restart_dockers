import subprocess
from time import sleep

try:
    import docker
except ImportError:
    print("Docker python library not found. \n Installing")
    subprocess.call(['pip3', 'install','docker'])
    import docker


client = docker.from_env()

# Stop and remove a named container if it exists (meaning is running or have exited).
def restart_container( imageName, containerName ):   
    containerExists=subprocess.check_output(['docker', 'ps','-aqf name=%s' % containerName])
    if containerExists:
        print('Stop container')
        subprocess.call(['docker', 'stop','%s' % containerName])
        sleep(15)
        subprocess.call(['docker', 'start','%s' % containerName])
    return


def restart_containers():
    stopped_docker_container = client.containers.list(all=True)
    for container in stopped_docker_container:
        restart_container(container.short_id, container.name)
    
    #1) Get list of stopped containers

restart_containers()
